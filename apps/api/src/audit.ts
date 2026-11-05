import { randomUUID } from 'node:crypto';
import type { LocalDatabase } from './database.js';

export function recordAudit(db: LocalDatabase, actorId: string, action: string, targetType: string, targetId: string): void {
  db.prepare('INSERT INTO audit_logs VALUES (?, ?, ?, ?, ?, ?)')
    .run(randomUUID(), actorId, action, targetType, targetId, new Date().toISOString());
}
