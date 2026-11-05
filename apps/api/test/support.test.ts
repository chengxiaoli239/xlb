import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import request from 'supertest';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createApp } from '../src/app.js';
import { createDatabase, hashPassword, type LocalDatabase } from '../src/database.js';

describe('local support workflow', () => {
  let db: LocalDatabase;
  let app: ReturnType<typeof createApp>;
  beforeEach(() => {
    db = createDatabase(':memory:');
    app = createApp(db);
  });
  afterEach(() => db.close());

  async function login(email = 'member@xlb.local', password = 'member-local-2026') {
    const response = await request(app).post('/api/v1/auth/login').send({ email, password }).expect(200);
    return response.headers['set-cookie'];
  }
  async function createTicket(cookie: string | string[]) {
    return request(app).post('/api/v1/me/tickets').set('Cookie', cookie).send({
      category: 'connection', subject: '连接一直失败', description: '客户端提示连接失败，发生在今天上午。',
    }).expect(201);
  }

  it('migrates an existing local database without deleting demo records', () => {
    const dir = mkdtempSync(join(tmpdir(), 'xlb-migration-'));
    const path = join(dir, 'existing.sqlite');
    try {
      const old = createDatabase(path);
      old.exec('DROP TABLE support_ticket_events; DROP TABLE support_tickets;');
      old.pragma('user_version = 1');
      old.close();
      const migrated = createDatabase(path);
      expect(migrated.pragma('user_version', { simple: true })).toBe(2);
      expect(migrated.prepare('SELECT id FROM orders WHERE id = ?').get('order-demo-001')).toBeTruthy();
      expect(migrated.prepare('SELECT COUNT(*) AS total FROM support_tickets').get()).toEqual({ total: 0 });
      migrated.close();
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  it('keeps member tickets private and rejects credential text', async () => {
    const member = await login();
    const created = await createTicket(member);
    const id = created.body.data.id as string;
    const admin = await login('admin@xlb.local', 'admin-local-2026');
    expect(created.headers.location).toBe('/api/v1/me/tickets/' + id);
    await request(app).get('/api/v1/me/tickets/' + id).expect(401);
    await request(app).patch('/api/v1/admin/tickets/' + id).set('Cookie', member)
      .send({ status: 'resolved' }).expect(403);
    db.prepare('INSERT INTO users VALUES (?, ?, ?, ?, ?, ?, ?)').run(
      'user-other', 'other@xlb.local', '其他会员', 'member', 'active', hashPassword('other-local-password'), new Date().toISOString(),
    );
    const other = await login('other@xlb.local', 'other-local-password');
    await request(app).get('/api/v1/me/tickets/' + id).set('Cookie', other).expect(404);
    await request(app).post('/api/v1/me/tickets/' + id + '/replies').set('Cookie', other)
      .send({ body: '请帮我看看' }).expect(404);
    const ownList = await request(app).get('/api/v1/me/tickets').set('Cookie', member).expect(200);
    expect(ownList.body.meta.total).toBe(1);
    expect(ownList.body.data[0].id).toBe(id);
    const adminList = await request(app).get('/api/v1/admin/tickets').set('Cookie', admin).expect(200);
    expect(adminList.body.meta.total).toBe(1);
    await request(app).post('/api/v1/me/tickets').set('Cookie', member).send({
      category: 'subscription', subject: '订阅失效', description: '我的地址是 /subscribe/abcdefghijklmnopqrstuvwxyz',
    }).expect(400).expect(({ body }) => expect(body.error.code).toBe('credential_not_allowed'));
  });

  it('records permitted transitions, replies, reopening and audit without duplicate events', async () => {
    const member = await login();
    const admin = await login('admin@xlb.local', 'admin-local-2026');
    const created = await createTicket(member);
    const id = created.body.data.id as string;
    await request(app).patch('/api/v1/admin/tickets/' + id).set('Cookie', admin)
      .send({ status: 'in_progress' }).expect(200);
    await request(app).patch('/api/v1/admin/tickets/' + id).set('Cookie', admin)
      .send({ status: 'in_progress' }).expect(200);
    await request(app).post('/api/v1/admin/tickets/' + id + '/replies').set('Cookie', admin)
      .send({ body: '我们正在检查节点状态，请稍后重试。' }).expect(201);
    await request(app).patch('/api/v1/admin/tickets/' + id).set('Cookie', admin)
      .send({ status: 'resolved' }).expect(200);
    await request(app).patch('/api/v1/admin/tickets/' + id).set('Cookie', admin)
      .send({ status: 'open' }).expect(409);
    const reopened = await request(app).post('/api/v1/me/tickets/' + id + '/replies').set('Cookie', member)
      .send({ body: '我这里仍然无法连接。' }).expect(201);
    expect(reopened.body.data.status).toBe('open');
    const detail = await request(app).get('/api/v1/me/tickets/' + id).set('Cookie', member).expect(200);
    expect(detail.body.data.status).toBe('open');
    expect(detail.body.data.resolved_at).toBeNull();
    expect(detail.body.data.events.map((event: { kind: string }) => event.kind)).toEqual([
      'message', 'status_changed', 'message', 'status_changed', 'status_changed', 'message',
    ]);
    const audit = db.prepare('SELECT action FROM audit_logs WHERE target_id = ? ORDER BY created_at, rowid').all(id) as Array<{ action: string }> ;
    expect(audit.map(item => item.action)).toEqual([
      'ticket.created', 'ticket.status_changed', 'ticket.replied', 'ticket.status_changed', 'ticket.status_changed', 'ticket.replied',
    ]);
  });

  it('keeps the original resolution time when an administrator adds a follow-up', async () => {
    const member = await login();
    const admin = await login('admin@xlb.local', 'admin-local-2026');
    const id = (await createTicket(member)).body.data.id as string;
    await request(app).patch('/api/v1/admin/tickets/' + id).set('Cookie', admin)
      .send({ status: 'resolved' }).expect(200);
    const before = await request(app).get('/api/v1/admin/tickets/' + id).set('Cookie', admin).expect(200);
    await request(app).post('/api/v1/admin/tickets/' + id + '/replies').set('Cookie', admin)
      .send({ body: '处理结果补充说明。' }).expect(201);
    const after = await request(app).get('/api/v1/admin/tickets/' + id).set('Cookie', admin).expect(200);
    expect(after.body.data.status).toBe('resolved');
    expect(after.body.data.resolved_at).toBe(before.body.data.resolved_at);
  });
});
