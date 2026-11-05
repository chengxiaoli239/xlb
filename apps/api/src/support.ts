import { randomUUID } from 'node:crypto';
import { Router, type Request, type RequestHandler, type Response } from 'express';
import { z } from 'zod';
import type { LocalDatabase } from './database.js';
import { recordAudit } from './audit.js';

type TicketStatus = 'open' | 'in_progress' | 'resolved';
type Actor = { id: string; role: 'admin' | 'member' };
type SupportRequest = Request & { user?: Actor };
type TicketRow = { id: string; user_id: string; status: TicketStatus; subject: string; resolved_at: string | null };

const categories = z.enum(['connection', 'subscription', 'payment', 'account', 'other']);
const newTicket = z.strictObject({
  category: categories,
  subject: z.string().trim().min(3).max(100),
  description: z.string().trim().min(10).max(2000),
});
const newReply = z.strictObject({ body: z.string().trim().min(2).max(2000) });
const statusUpdate = z.strictObject({ status: z.enum(['open', 'in_progress', 'resolved']) });
const listQuery = z.object({
  page: z.coerce.number().int().min(1).max(100000).default(1),
  per_page: z.coerce.number().int().min(1).max(50).default(20),
});
const transitions: Record<TicketStatus, TicketStatus[]> = {
  open: ['in_progress', 'resolved'],
  in_progress: ['open', 'resolved'],
  resolved: ['in_progress'],
};

function containsCredential(text: string): boolean {
  return /\/subscribe\/[A-Za-z0-9_-]{8,}|xlb_session\s*=/i.test(text);
}

function findTicket(db: LocalDatabase, id: string, actor: Actor) {
  return db.prepare('SELECT t.id, t.user_id, t.category, t.subject, t.status, t.created_at, t.updated_at, t.resolved_at, u.name AS user_name, u.email AS user_email FROM support_tickets t JOIN users u ON u.id = t.user_id WHERE t.id = ? AND (? = 1 OR t.user_id = ?)')
    .get(id, actor.role === 'admin' ? 1 : 0, actor.id) as (TicketRow & Record<string, unknown>) | undefined;
}

function listTickets(db: LocalDatabase, req: Request, res: Response, admin: boolean) {
  const parsed = listQuery.safeParse(req.query);
  if (!parsed.success) return res.status(400).json({ error: { code: 'validation_error', message: '分页参数无效' } });
  const { page, per_page } = parsed.data;
  const actor = (req as SupportRequest).user!;
  const filter = admin ? '' : ' WHERE t.user_id = ?';
  const params = admin ? [] : [actor.id];
  const total = (db.prepare('SELECT COUNT(*) AS count FROM support_tickets t' + filter).get(...params) as { count: number }).count;
  const rows = db.prepare('SELECT t.id, t.category, t.subject, t.status, t.created_at, t.updated_at, t.resolved_at, u.name AS user_name FROM support_tickets t JOIN users u ON u.id = t.user_id' + filter + ' ORDER BY t.updated_at DESC, t.id DESC LIMIT ? OFFSET ?')
    .all(...params, per_page, (page - 1) * per_page);
  res.json({ data: rows, meta: { total, page, per_page, total_pages: Math.ceil(total / per_page) } });
}

function ticketDetail(db: LocalDatabase, req: Request, res: Response) {
  const ticket = findTicket(db, String(req.params.id), (req as SupportRequest).user!);
  if (!ticket) return res.status(404).json({ error: { code: 'not_found', message: '工单不存在' } });
  const events = db.prepare('SELECT e.id, e.kind, e.body, e.from_status, e.to_status, e.created_at, u.name AS actor_name, u.role AS actor_role FROM support_ticket_events e JOIN users u ON u.id = e.actor_user_id WHERE e.ticket_id = ? ORDER BY e.created_at, e.rowid')
    .all(ticket.id);
  res.json({ data: { ...ticket, events } });
}

export function createSupportRouter(db: LocalDatabase, requireAuth: RequestHandler, requireAdmin: RequestHandler) {
  const router = Router();

  router.get('/me/tickets', requireAuth, (req, res) => listTickets(db, req, res, false));
  router.get('/me/tickets/:id', requireAuth, (req, res) => ticketDetail(db, req, res));
  router.post('/me/tickets', requireAuth, (req: SupportRequest, res) => {
    const parsed = newTicket.safeParse(req.body);
    if (!parsed.success) return res.status(400).json({ error: { code: 'validation_error', message: '请检查工单类别、标题和描述' } });
    if (containsCredential(parsed.data.subject + ' ' + parsed.data.description)) {
      return res.status(400).json({ error: { code: 'credential_not_allowed', message: '工单中不要填写订阅地址或会话凭证' } });
    }
    const id = randomUUID(), eventId = randomUUID(), now = new Date().toISOString();
    db.transaction(() => {
      db.prepare('INSERT INTO support_tickets VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        .run(id, req.user!.id, parsed.data.category, parsed.data.subject, 'open', now, now, null);
      db.prepare('INSERT INTO support_ticket_events VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        .run(eventId, id, req.user!.id, 'message', parsed.data.description, null, null, now);
      recordAudit(db, req.user!.id, 'ticket.created', 'support_ticket', id);
    })();
    res.location('/api/v1/me/tickets/' + id).status(201).json({ data: { id, status: 'open', created_at: now } });
  });

  const reply = (req: SupportRequest, res: Response) => {
    const parsed = newReply.safeParse(req.body);
    if (!parsed.success) return res.status(400).json({ error: { code: 'validation_error', message: '回复需为 2–2000 字' } });
    if (containsCredential(parsed.data.body)) {
      return res.status(400).json({ error: { code: 'credential_not_allowed', message: '回复中不要填写订阅地址或会话凭证' } });
    }
    const actor = req.user!;
    const ticket = findTicket(db, String(req.params.id), actor);
    if (!ticket) return res.status(404).json({ error: { code: 'not_found', message: '工单不存在' } });
    const nextStatus = actor.role === 'member' && ticket.status === 'resolved' ? 'open'
      : actor.role === 'admin' && ticket.status === 'open' ? 'in_progress' : ticket.status;
    const eventId = randomUUID(), now = new Date().toISOString();
    db.transaction(() => {
      db.prepare('UPDATE support_tickets SET status = ?, updated_at = ?, resolved_at = ? WHERE id = ?')
        .run(nextStatus, now, nextStatus === 'resolved' ? ticket.resolved_at : null, ticket.id);
      if (nextStatus !== ticket.status) {
        db.prepare('INSERT INTO support_ticket_events VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
          .run(randomUUID(), ticket.id, actor.id, 'status_changed', null, ticket.status, nextStatus, now);
        recordAudit(db, actor.id, 'ticket.status_changed', 'support_ticket', ticket.id);
      }
      db.prepare('INSERT INTO support_ticket_events VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        .run(eventId, ticket.id, actor.id, 'message', parsed.data.body, null, null, now);
      recordAudit(db, actor.id, 'ticket.replied', 'support_ticket', ticket.id);
    })();
    res.location('/api/v1/' + (actor.role === 'admin' ? 'admin' : 'me') + '/tickets/' + ticket.id)
      .status(201).json({ data: { id: eventId, ticket_id: ticket.id, status: nextStatus } });
  };
  router.post('/me/tickets/:id/replies', requireAuth, reply);

  router.get('/admin/tickets', requireAuth, requireAdmin, (req, res) => listTickets(db, req, res, true));
  router.get('/admin/tickets/:id', requireAuth, requireAdmin, (req, res) => ticketDetail(db, req, res));
  router.post('/admin/tickets/:id/replies', requireAuth, requireAdmin, reply);
  router.patch('/admin/tickets/:id', requireAuth, requireAdmin, (req: SupportRequest, res) => {
    const parsed = statusUpdate.safeParse(req.body);
    if (!parsed.success) return res.status(400).json({ error: { code: 'validation_error', message: '工单状态无效' } });
    const ticket = findTicket(db, String(req.params.id), req.user!);
    if (!ticket) return res.status(404).json({ error: { code: 'not_found', message: '工单不存在' } });
    if (ticket.status === parsed.data.status) return res.json({ data: { id: ticket.id, status: ticket.status } });
    if (!transitions[ticket.status].includes(parsed.data.status)) {
      return res.status(409).json({ error: { code: 'invalid_transition', message: '当前状态不允许该操作' } });
    }
    const now = new Date().toISOString();
    db.transaction(() => {
      db.prepare('UPDATE support_tickets SET status = ?, updated_at = ?, resolved_at = ? WHERE id = ?')
        .run(parsed.data.status, now, parsed.data.status === 'resolved' ? now : null, ticket.id);
      db.prepare('INSERT INTO support_ticket_events VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        .run(randomUUID(), ticket.id, req.user!.id, 'status_changed', null, ticket.status, parsed.data.status, now);
      recordAudit(db, req.user!.id, 'ticket.status_changed', 'support_ticket', ticket.id);
    })();
    res.json({ data: { id: ticket.id, status: parsed.data.status, updated_at: now } });
  });
  return router;
}
