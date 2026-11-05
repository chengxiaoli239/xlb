import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import request from 'supertest';
import { createApp } from '../src/app.js';
import { createDatabase } from '../src/database.js';
import type { LocalDatabase } from '../src/database.js';

describe('local platform API', () => {
  let db: LocalDatabase;
  let app: ReturnType<typeof createApp>;
  beforeEach(() => {
    db = createDatabase(':memory:');
    app = createApp(db);
  });
  afterEach(() => db.close());

  it('isolates the administrator routes from a member account', async () => {
    const login = await request(app).post('/api/v1/auth/login')
      .send({ email: 'member@xlb.local', password: 'member-local-2026' }).expect(200);
    await request(app).get('/api/v1/admin/nodes').set('Cookie', login.headers['set-cookie']).expect(403);
    await request(app).get('/api/v1/me/overview').set('Cookie', login.headers['set-cookie']).expect(200);
  });

  it('creates, serves, rotates, and revokes a test subscription', async () => {
    const login = await request(app).post('/api/v1/auth/login')
      .send({ email: 'member@xlb.local', password: 'member-local-2026' }).expect(200);
    const cookie = login.headers['set-cookie'];
    const created = await request(app).post('/api/v1/me/subscriptions').set('Cookie', cookie).expect(201);
    const url = created.body.data.url as string;
    await request(app).get(url).expect(200).expect(/type: socks5/).expect(/198\.51\.100\.10/);
    const rotated = await request(app).post('/api/v1/me/subscriptions/' + created.body.data.id + '/rotate')
      .set('Cookie', cookie).expect(200);
    await request(app).get(url).expect(404);
    await request(app).get(rotated.body.data.url).expect(200);
    await request(app).post('/api/v1/me/subscriptions/' + rotated.body.data.id + '/revoke')
      .set('Cookie', cookie).expect(204);
    await request(app).get(rotated.body.data.url).expect(404);
  });

  it('allows only administrators to change a test node state', async () => {
    const login = await request(app).post('/api/v1/auth/login')
      .send({ email: 'admin@xlb.local', password: 'admin-local-2026' }).expect(200);
    const cookie = login.headers['set-cookie'];
    await request(app).patch('/api/v1/admin/nodes/node-test-1').set('Cookie', cookie)
      .send({ status: 'maintenance' }).expect(200);
    await request(app).get('/api/v1/admin/nodes').set('Cookie', cookie)
      .expect(200).expect(({ body }) => expect(body.data[0].status).toBe('maintenance'));
  });
});
