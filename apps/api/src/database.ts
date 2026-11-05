import Database from 'better-sqlite3';
import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { scryptSync } from 'node:crypto';

export type LocalDatabase = InstanceType<typeof Database>;

const schema = [
  'PRAGMA foreign_keys = ON;',
  "CREATE TABLE IF NOT EXISTS users (id TEXT PRIMARY KEY, email TEXT NOT NULL UNIQUE, name TEXT NOT NULL, role TEXT NOT NULL CHECK (role IN ('admin','member')), status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','suspended')), password_hash TEXT NOT NULL, created_at TEXT NOT NULL);",
  'CREATE TABLE IF NOT EXISTS plans (id TEXT PRIMARY KEY, name TEXT NOT NULL, period_label TEXT NOT NULL, duration_days INTEGER NOT NULL, price_cents INTEGER NOT NULL, traffic_gb REAL NOT NULL, max_devices INTEGER NOT NULL, active INTEGER NOT NULL DEFAULT 1);',
  "CREATE TABLE IF NOT EXISTS entitlements (id TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES users(id), plan_id TEXT NOT NULL REFERENCES plans(id), status TEXT NOT NULL CHECK (status IN ('active','expired','revoked')), starts_at TEXT NOT NULL, ends_at TEXT NOT NULL, data_used_gb REAL NOT NULL DEFAULT 0);",
  "CREATE TABLE IF NOT EXISTS nodes (id TEXT PRIMARY KEY, name TEXT NOT NULL, region TEXT NOT NULL, protocol TEXT NOT NULL, endpoint_host TEXT NOT NULL, endpoint_port INTEGER NOT NULL, status TEXT NOT NULL CHECK (status IN ('available','maintenance','disabled')), is_test INTEGER NOT NULL DEFAULT 1, max_sessions INTEGER NOT NULL DEFAULT 0, active_sessions INTEGER NOT NULL DEFAULT 0, latency_ms INTEGER, updated_at TEXT NOT NULL);",
  "CREATE TABLE IF NOT EXISTS subscriptions (id TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES users(id), name TEXT NOT NULL, token_hash TEXT NOT NULL UNIQUE, status TEXT NOT NULL CHECK (status IN ('active','revoked')), created_at TEXT NOT NULL, expires_at TEXT NOT NULL, last_used_at TEXT);",
  "CREATE TABLE IF NOT EXISTS orders (id TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES users(id), plan_id TEXT NOT NULL REFERENCES plans(id), total_cents INTEGER NOT NULL, status TEXT NOT NULL CHECK (status IN ('paid_test','pending','refunded')), created_at TEXT NOT NULL);",
  "CREATE TABLE IF NOT EXISTS commission_entries (id TEXT PRIMARY KEY, order_id TEXT NOT NULL REFERENCES orders(id), partner_name TEXT NOT NULL, amount_cents INTEGER NOT NULL, status TEXT NOT NULL CHECK (status IN ('pending','settled','reversed')), created_at TEXT NOT NULL);",
  'CREATE TABLE IF NOT EXISTS sessions (token_hash TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES users(id), expires_at TEXT NOT NULL);',
  'CREATE TABLE IF NOT EXISTS audit_logs (id TEXT PRIMARY KEY, actor_user_id TEXT NOT NULL REFERENCES users(id), action TEXT NOT NULL, target_type TEXT NOT NULL, target_id TEXT NOT NULL, created_at TEXT NOT NULL);',
  'CREATE INDEX IF NOT EXISTS idx_entitlements_user_status ON entitlements(user_id, status);',
  'CREATE INDEX IF NOT EXISTS idx_subscriptions_user_status ON subscriptions(user_id, status);',
  'CREATE INDEX IF NOT EXISTS idx_orders_created ON orders(created_at);',
  'CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at);',
].join('\n');

export function hashPassword(password: string): string {
  return scryptSync(password, 'xlb-local-seed', 64).toString('hex');
}

export function createDatabase(file = './data/xlb-local.sqlite', seed = true): LocalDatabase {
  if (file !== ':memory:') mkdirSync(dirname(resolve(file)), { recursive: true });
  const db = new Database(file);
  db.pragma('journal_mode = WAL');
  db.exec(schema);
  if (seed) seedDatabase(db);
  return db;
}

export function seedDatabase(db: LocalDatabase): void {
  const now = new Date();
  const timestamp = now.toISOString();
  const insertUser = db.prepare('INSERT OR IGNORE INTO users VALUES (?, ?, ?, ?, ?, ?, ?)');
  insertUser.run('user-admin', 'admin@xlb.local', '系统管理员', 'admin', 'active', hashPassword(process.env.LOCAL_ADMIN_PASSWORD ?? 'admin-local-2026'), timestamp);
  insertUser.run('user-member', 'member@xlb.local', '演示会员', 'member', 'active', hashPassword(process.env.LOCAL_MEMBER_PASSWORD ?? 'member-local-2026'), timestamp);

  const plans = [
    ['weekly', '周会员', '1 周', 7, 900, 10, 2],
    ['monthly', '月会员', '1 个月', 30, 2980, 100, 2],
    ['quarterly', '季会员', '1 季度', 90, 7980, 300, 4],
    ['annual', '年会员', '1 年', 365, 27880, 1500, 5],
  ] as const;
  const insertPlan = db.prepare('INSERT OR IGNORE INTO plans VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
  for (const plan of plans) insertPlan.run(...plan);

  const start = new Date(now);
  start.setDate(start.getDate() - 16);
  const end = new Date(start);
  end.setDate(end.getDate() + 365);
  db.prepare("INSERT OR IGNORE INTO entitlements VALUES ('entitlement-member-annual', 'user-member', 'annual', 'active', ?, ?, 326.4)")
    .run(start.toISOString(), end.toISOString());
  db.prepare("INSERT OR IGNORE INTO nodes VALUES ('node-test-1', '演示节点 01', '测试区域', 'SOCKS5', '198.51.100.10', 1080, 'available', 1, 100, 12, 38, ?)")
    .run(timestamp);
  db.prepare("INSERT OR IGNORE INTO orders VALUES ('order-demo-001', 'user-member', 'annual', 27880, 'paid_test', ?)")
    .run(timestamp);
  db.prepare("INSERT OR IGNORE INTO commission_entries VALUES ('commission-demo-001', 'order-demo-001', '演示渠道 A', 4170, 'pending', ?)")
    .run(timestamp);
}
