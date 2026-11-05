import express, { type NextFunction, type Request, type Response } from 'express';
import cookieParser from 'cookie-parser';
import helmet from 'helmet';
import { createHash, randomBytes, randomUUID, timingSafeEqual } from 'node:crypto';
import { z } from 'zod';
import type { LocalDatabase } from './database.js';
import { hashPassword } from './database.js';
import { buildDemoProfile, createSubscriptionToken, hashToken } from './subscriptions.js';

interface UserRow {
  id: string; email: string; name: string;
  role: 'admin' | 'member'; status: 'active' | 'suspended';
}
interface AuthRequest extends Request { user?: UserRow }
const cookieName = 'xlb_session';
const day = 24 * 60 * 60 * 1000;
const hash = (value: string) => createHash('sha256').update(value).digest('hex');
const publicUser = (user: UserRow) => ({ id: user.id, email: user.email, name: user.name, role: user.role });

function audit(db: LocalDatabase, actor: UserRow, action: string, targetType: string, targetId: string) {
  db.prepare('INSERT INTO audit_logs VALUES (?, ?, ?, ?, ?, ?)')
    .run(randomUUID(), actor.id, action, targetType, targetId, new Date().toISOString());
}

export function createApp(db: LocalDatabase) {
  const app = express();
  app.disable('x-powered-by');
  app.use(helmet({ crossOriginResourcePolicy: { policy: 'same-site' } }));
  app.use(express.json({ limit: '32kb' }));
  app.use(cookieParser());
  app.use((req: AuthRequest, _res, next) => {
    const token = req.cookies[cookieName];
    if (token) {
      const user = db.prepare('SELECT users.id, users.email, users.name, users.role, users.status FROM sessions JOIN users ON users.id = sessions.user_id WHERE sessions.token_hash = ? AND sessions.expires_at > ?')
        .get(hash(token), new Date().toISOString()) as UserRow | undefined;
      if (user?.status === 'active') req.user = user;
    }
    next();
  });
  const requireAuth = (req: AuthRequest, res: Response, next: NextFunction) => {
    if (!req.user) return res.status(401).json({ error: { code: 'unauthorized', message: '请先登录' } });
    next();
  };
  const requireAdmin = (req: AuthRequest, res: Response, next: NextFunction) => {
    if (req.user?.role !== 'admin') return res.status(403).json({ error: { code: 'forbidden', message: '需要管理员权限' } });
    next();
  };

  app.get('/api/health', (_req, res) => res.json({ status: 'ok', mode: 'local-development' }));
  app.get('/api/v1/auth/session', (req: AuthRequest, res) => res.json({ data: req.user ? publicUser(req.user) : null }));
  app.post('/api/v1/auth/login', (req, res) => {
    const parsed = z.object({ email: z.string().email().max(190), password: z.string().min(1).max(200) }).safeParse(req.body);
    if (!parsed.success) return res.status(400).json({ error: { code: 'validation_error', message: '请检查邮箱和密码' } });
    const user = db.prepare('SELECT id, email, name, role, status, password_hash FROM users WHERE email = ?')
      .get(parsed.data.email.toLowerCase()) as (UserRow & { password_hash: string }) | undefined;
    const expected = Buffer.from(user?.password_hash ?? hashPassword('invalid-local-password'), 'hex');
    const attempted = Buffer.from(hashPassword(parsed.data.password), 'hex');
    if (!timingSafeEqual(attempted, expected) || !user || user.status !== 'active') {
      return res.status(401).json({ error: { code: 'invalid_credentials', message: '邮箱或密码不正确' } });
    }
    const token = randomBytes(32).toString('base64url');
    db.prepare('INSERT INTO sessions VALUES (?, ?, ?)')
      .run(hash(token), user.id, new Date(Date.now() + day).toISOString());
    res.cookie(cookieName, token, { httpOnly: true, sameSite: 'lax', secure: process.env.NODE_ENV === 'production', maxAge: day, path: '/' });
    const { password_hash: _passwordHash, ...safeUser } = user;
    res.json({ data: publicUser(safeUser) });
  });
  app.post('/api/v1/auth/logout', (req: AuthRequest, res) => {
    const token = req.cookies[cookieName];
    if (token) db.prepare('DELETE FROM sessions WHERE token_hash = ?').run(hash(token));
    res.clearCookie(cookieName, { httpOnly: true, sameSite: 'lax', path: '/' });
    res.status(204).end();
  });
  app.get('/api/v1/catalog/plans', (_req, res) => {
    res.json({ data: db.prepare('SELECT id, name, period_label, duration_days, price_cents, traffic_gb, max_devices FROM plans WHERE active = 1 ORDER BY duration_days').all() });
  });

  app.get('/api/v1/me/overview', requireAuth, (req: AuthRequest, res) => {
    const entitlement = db.prepare('SELECT e.id, e.status, e.starts_at, e.ends_at, e.data_used_gb, p.id AS plan_id, p.name AS plan_name, p.period_label, p.price_cents, p.traffic_gb, p.max_devices FROM entitlements e JOIN plans p ON p.id = e.plan_id WHERE e.user_id = ? AND e.status = ? AND e.ends_at > ? ORDER BY e.ends_at DESC LIMIT 1')
      .get(req.user!.id, 'active', new Date().toISOString());
    const subs = db.prepare('SELECT COUNT(*) AS count FROM subscriptions WHERE user_id = ? AND status = ? AND expires_at > ?')
      .get(req.user!.id, 'active', new Date().toISOString()) as { count: number };
    const orders = db.prepare('SELECT o.id, o.total_cents, o.status, o.created_at, p.name AS plan_name FROM orders o JOIN plans p ON p.id = o.plan_id WHERE o.user_id = ? ORDER BY o.created_at DESC LIMIT 5')
      .all(req.user!.id);
    res.json({ data: { user: publicUser(req.user!), entitlement, activeSubscriptions: subs.count, recentOrders: orders } });
  });
  app.get('/api/v1/me/subscriptions', requireAuth, (req: AuthRequest, res) => {
    res.json({ data: db.prepare('SELECT id, name, status, created_at, expires_at, last_used_at FROM subscriptions WHERE user_id = ? ORDER BY created_at DESC').all(req.user!.id) });
  });
  app.post('/api/v1/me/subscriptions', requireAuth, (req: AuthRequest, res) => {
    const active = db.prepare('SELECT COUNT(*) AS count FROM subscriptions WHERE user_id = ? AND status = ?')
      .get(req.user!.id, 'active') as { count: number };
    if (active.count >= 3) return res.status(409).json({ error: { code: 'subscription_limit', message: '最多保留 3 个有效订阅' } });
    const entitlement = db.prepare('SELECT ends_at FROM entitlements WHERE user_id = ? AND status = ? AND ends_at > ? ORDER BY ends_at DESC LIMIT 1')
      .get(req.user!.id, 'active', new Date().toISOString()) as { ends_at: string } | undefined;
    if (!entitlement) return res.status(403).json({ error: { code: 'membership_required', message: '当前账户没有有效会员权益' } });
    const id = randomUUID(), secret = createSubscriptionToken(), created = new Date().toISOString();
    db.prepare('INSERT INTO subscriptions (id, user_id, name, token_hash, status, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
      .run(id, req.user!.id, 'Clash 订阅', hashToken(secret), 'active', created, entitlement.ends_at);
    audit(db, req.user!, 'subscription.created', 'subscription', id);
    res.status(201).json({ data: { id, name: 'Clash 订阅', status: 'active', created_at: created, expires_at: entitlement.ends_at, url: '/subscribe/' + secret } });
  });
  app.post('/api/v1/me/subscriptions/:id/rotate', requireAuth, (req: AuthRequest, res) => {
    const current = db.prepare('SELECT id, expires_at FROM subscriptions WHERE id = ? AND user_id = ? AND status = ?')
      .get(req.params.id, req.user!.id, 'active') as { id: string; expires_at: string } | undefined;
    if (!current) return res.status(404).json({ error: { code: 'not_found', message: '订阅不存在或已撤销' } });
    const secret = createSubscriptionToken(), nextId = randomUUID(), created = new Date().toISOString();
    db.transaction(() => {
      db.prepare('UPDATE subscriptions SET status = ? WHERE id = ?').run('revoked', current.id);
      db.prepare('INSERT INTO subscriptions (id, user_id, name, token_hash, status, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        .run(nextId, req.user!.id, 'Clash 订阅', hashToken(secret), 'active', created, current.expires_at);
      audit(db, req.user!, 'subscription.rotated', 'subscription', current.id);
    })();
    res.json({ data: { id: nextId, url: '/subscribe/' + secret, status: 'active', created_at: created, expires_at: current.expires_at } });
  });
  app.post('/api/v1/me/subscriptions/:id/revoke', requireAuth, (req: AuthRequest, res) => {
    const subscriptionId = String(req.params.id);
    const result = db.prepare('UPDATE subscriptions SET status = ? WHERE id = ? AND user_id = ? AND status = ?')
      .run('revoked', subscriptionId, req.user!.id, 'active');
    if (!result.changes) return res.status(404).json({ error: { code: 'not_found', message: '订阅不存在或已撤销' } });
    audit(db, req.user!, 'subscription.revoked', 'subscription', subscriptionId);
    res.status(204).end();
  });
  app.get('/api/v1/me/orders', requireAuth, (req: AuthRequest, res) => {
    res.json({ data: db.prepare('SELECT o.id, p.name AS plan_name, o.total_cents, o.status, o.created_at FROM orders o JOIN plans p ON p.id = o.plan_id WHERE o.user_id = ? ORDER BY o.created_at DESC').all(req.user!.id) });
  });

  app.get('/api/v1/admin/summary', requireAuth, requireAdmin, (_req, res) => {
    const members = db.prepare('SELECT COUNT(*) AS count FROM users WHERE role = ?').get('member') as { count: number };
    const entitlements = db.prepare('SELECT COUNT(*) AS count FROM entitlements WHERE status = ? AND ends_at > ?').get('active', new Date().toISOString()) as { count: number };
    const subscriptions = db.prepare('SELECT COUNT(*) AS count FROM subscriptions WHERE status = ? AND expires_at > ?').get('active', new Date().toISOString()) as { count: number };
    const nodes = db.prepare('SELECT COUNT(*) AS count FROM nodes WHERE status = ?').get('available') as { count: number };
    const commissions = db.prepare('SELECT COALESCE(SUM(amount_cents), 0) AS amount FROM commission_entries WHERE status = ?').get('pending') as { amount: number };
    const events = db.prepare('SELECT a.id, a.action, a.target_type, a.target_id, a.created_at, u.name AS actor FROM audit_logs a JOIN users u ON u.id = a.actor_user_id ORDER BY a.created_at DESC LIMIT 8').all();
    res.json({ data: { memberCount: members.count, activeEntitlements: entitlements.count, activeSubscriptions: subscriptions.count, availableNodes: nodes.count, pendingCommissionCents: commissions.amount, recentAudit: events } });
  });
  app.get('/api/v1/admin/users', requireAuth, requireAdmin, (_req, res) => {
    const rows = db.prepare('SELECT u.id, u.email, u.name, u.status, u.created_at, p.name AS plan_name, e.ends_at, e.data_used_gb, p.traffic_gb FROM users u LEFT JOIN entitlements e ON e.user_id = u.id AND e.status = ? LEFT JOIN plans p ON p.id = e.plan_id WHERE u.role = ? ORDER BY u.created_at DESC').all('active', 'member');
    res.json({ data: rows });
  });
  app.get('/api/v1/admin/nodes', requireAuth, requireAdmin, (_req, res) => {
    res.json({ data: db.prepare('SELECT id, name, region, protocol, endpoint_host, endpoint_port, status, is_test, max_sessions, active_sessions, latency_ms, updated_at FROM nodes ORDER BY name').all() });
  });
  app.patch('/api/v1/admin/nodes/:id', requireAuth, requireAdmin, (req: AuthRequest, res) => {
    const nodeId = String(req.params.id);
    const parsed = z.object({ status: z.enum(['available', 'maintenance', 'disabled']) }).safeParse(req.body);
    if (!parsed.success) return res.status(400).json({ error: { code: 'validation_error', message: '节点状态无效' } });
    const result = db.prepare('UPDATE nodes SET status = ?, updated_at = ? WHERE id = ?')
      .run(parsed.data.status, new Date().toISOString(), nodeId);
    if (!result.changes) return res.status(404).json({ error: { code: 'not_found', message: '节点不存在' } });
    audit(db, req.user!, 'node.status_changed', 'node', nodeId);
    res.json({ data: { id: nodeId, status: parsed.data.status } });
  });
  app.get('/api/v1/admin/commissions', requireAuth, requireAdmin, (_req, res) => {
    const rows = db.prepare('SELECT c.id, c.partner_name, c.amount_cents, c.status, c.created_at, o.id AS order_id FROM commission_entries c JOIN orders o ON o.id = c.order_id ORDER BY c.created_at DESC').all();
    res.json({ data: rows });
  });

  app.get('/subscribe/:token', (req, res) => {
    const record = db.prepare('SELECT id, user_id, status, expires_at FROM subscriptions WHERE token_hash = ?')
      .get(hashToken(String(req.params.token))) as { id: string; user_id: string; status: string; expires_at: string } | undefined;
    const now = new Date().toISOString();
    if (!record || record.status !== 'active' || record.expires_at <= now) return res.status(404).type('text/plain').send('Subscription unavailable');
    const entitlement = db.prepare('SELECT 1 FROM entitlements WHERE user_id = ? AND status = ? AND ends_at > ? LIMIT 1')
      .get(record.user_id, 'active', now);
    const node = db.prepare('SELECT name, endpoint_host, endpoint_port FROM nodes WHERE status = ? AND is_test = 1 LIMIT 1')
      .get('available') as { name: string; endpoint_host: string; endpoint_port: number } | undefined;
    if (!entitlement || !node) return res.status(404).type('text/plain').send('Subscription unavailable');
    db.prepare('UPDATE subscriptions SET last_used_at = ? WHERE id = ?').run(now, record.id);
    res.setHeader('Cache-Control', 'no-store');
    res.type('text/yaml').send(buildDemoProfile(node));
  });
  app.use((error: unknown, _req: Request, res: Response, _next: NextFunction) => {
    console.error('Local API error', error);
    res.status(500).json({ error: { code: 'internal_error', message: '本地服务发生错误' } });
  });
  return app;
}
