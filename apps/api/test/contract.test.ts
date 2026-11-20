import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import request from 'supertest';
import { createApp } from '../src/app.js';
import { createDatabase, type LocalDatabase } from '../src/database.js';

describe('core API contracts', () => {
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

  it('validates login input and returns a stable credential failure shape', async () => {
    await request(app).post('/api/v1/auth/login').send({ email: 'not-an-email', password: '' })
      .expect(400).expect(({ body }) => {
        expect(body).toEqual({ error: { code: 'validation_error', message: '请检查邮箱和密码' } });
      });
    await request(app).post('/api/v1/auth/login').send({ email: 'member@xlb.local', password: 'wrong' })
      .expect(401).expect(({ body }) => {
        expect(body.error).toMatchObject({ code: 'invalid_credentials' });
        expect(body.error).not.toHaveProperty('password_hash');
      });
  });

  it('returns only public identity fields and rejects missing or insufficient authorization', async () => {
    const session = await request(app).get('/api/v1/auth/session').expect(200);
    expect(session.body).toEqual({ data: null });
    await request(app).get('/api/v1/me/overview').expect(401)
      .expect(({ body }) => expect(body.error.code).toBe('unauthorized'));
    await request(app).get('/api/v1/admin/nodes').expect(401);

    const cookie = await login();
    const loggedIn = await request(app).get('/api/v1/auth/session').set('Cookie', cookie).expect(200);
    expect(loggedIn.body.data).toEqual({
      id: 'user-member', email: 'member@xlb.local', name: '演示会员', role: 'member',
    });
    expect(JSON.stringify(loggedIn.body)).not.toMatch(/password_hash|token_hash|xlb_session/i);
    await request(app).get('/api/v1/admin/nodes').set('Cookie', cookie).expect(403)
      .expect(({ body }) => expect(body.error.code).toBe('forbidden'));
  });

  it('exposes plan device limits without exposing internal plan fields', async () => {
    const response = await request(app).get('/api/v1/catalog/plans').expect(200);
    expect(response.body.data).toHaveLength(4);
    expect(response.body.data.map((plan: { max_devices: number }) => plan.max_devices)).toEqual([2, 2, 4, 5]);
    expect(JSON.stringify(response.body)).not.toMatch(/password_hash|token_hash|active/i);
  });

  it('keeps member subscription data private and rejects inactive membership', async () => {
    const member = await login();
    const created = await request(app).post('/api/v1/me/subscriptions').set('Cookie', member).expect(201);
    expect(created.body.data).toMatchObject({ name: 'Clash 订阅', status: 'active' });
    expect(created.body.data.url).toMatch(/^\/subscribe\/[A-Za-z0-9_-]+$/);
    expect(JSON.stringify(created.body)).not.toMatch(/token_hash|password_hash|endpoint_host|198\.51\.100\.10/i);

    db.prepare("UPDATE entitlements SET ends_at = ? WHERE user_id = ?")
      .run(new Date(Date.now() - 60_000).toISOString(), 'user-member');
    await request(app).post('/api/v1/me/subscriptions').set('Cookie', member).expect(403)
      .expect(({ body }) => expect(body.error.code).toBe('membership_required'));
    await request(app).get(created.body.data.url).expect(404);
  });

  it('serves only a local test profile and immediately refuses it when its test node is unavailable', async () => {
    const member = await login();
    const created = await request(app).post('/api/v1/me/subscriptions').set('Cookie', member).expect(201);
    const profile = await request(app).get(created.body.data.url).expect(200);
    expect(profile.headers['cache-control']).toBe('no-store');
    expect(profile.text).toMatch(/本地开发配置/);
    expect(profile.text).toMatch(/type: socks5/);
    expect(profile.text).not.toMatch(/password:|private-key:|token_hash|password_hash/i);

    db.prepare('UPDATE nodes SET is_test = 0 WHERE id = ?').run('node-test-1');
    await request(app).get(created.body.data.url).expect(404).expect('Subscription unavailable');
  });

  it('rejects invalid node status values and does not leak node credentials', async () => {
    const admin = await login('admin@xlb.local', 'admin-local-2026');
    await request(app).patch('/api/v1/admin/nodes/node-test-1').set('Cookie', admin)
      .send({ status: 'online' }).expect(400)
      .expect(({ body }) => expect(body.error.code).toBe('validation_error'));
    const nodes = await request(app).get('/api/v1/admin/nodes').set('Cookie', admin).expect(200);
    expect(nodes.body.data[0]).toMatchObject({ id: 'node-test-1', is_test: 1, max_sessions: 100 });
    expect(JSON.stringify(nodes.body)).not.toMatch(/password|private.?key|secret|token_hash/i);
  });
});
