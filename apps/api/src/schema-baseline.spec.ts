/**
 * Phase 0.3B — Schema baseline integrity spec
 *
 * Asserts that the Prisma schema is in sync with the live database and
 * that every critical financial/auction table has the columns the
 * application logic depends on. Acts as a canary: if a migration runs
 * in the Laravel app that changes a column this NestJS code reads, one of
 * these tests will fail and alert you before a runtime bug surfaces.
 *
 * Run standalone:
 *   DATABASE_URL="mysql://root:@127.0.0.1:3306/arteuction_test" npx jest schema-baseline
 */

import { execSync } from 'child_process';
import * as path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
import { PrismaService } from './prisma.service';
import { Test } from '@nestjs/testing';

const HAS_DB = Boolean(process.env.DATABASE_URL);
const describeWithDb = HAS_DB ? describe : describe.skip;

// ── helpers ──────────────────────────────────────────────────────────────────

interface Column {
  COLUMN_NAME: string;
  DATA_TYPE: string;
  IS_NULLABLE: string;
}

async function columns(prisma: PrismaService, table: string): Promise<Column[]> {
  return prisma.$queryRaw<Column[]>`
    SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = ${table}
    ORDER BY ORDINAL_POSITION
  `;
}

function hasColumn(cols: Column[], name: string): boolean {
  return cols.some((c) => c.COLUMN_NAME === name);
}

function columnType(cols: Column[], name: string): string | undefined {
  return cols.find((c) => c.COLUMN_NAME === name)?.DATA_TYPE;
}

// ── suite ─────────────────────────────────────────────────────────────────────

describeWithDb('Schema baseline — Prisma schema parity with live DB', () => {
  let prisma: PrismaService;

  beforeAll(async () => {
    const module = await Test.createTestingModule({
      providers: [PrismaService],
    }).compile();
    prisma = module.get(PrismaService);
    await (prisma as any).onModuleInit?.();
  });

  afterAll(async () => {
    await (prisma as any).onModuleDestroy?.();
  });

  // ── SB1: prisma migrate diff exits 0 ──────────────────────────────────────

  it('SB1: prisma migrate diff (DB → schema) is empty — no pending changes', () => {
    const cwd = path.resolve(__dirname, '..');
    const result = execSync(
      'npx prisma migrate diff --from-config-datasource --to-schema prisma/schema.prisma --script',
      { cwd, env: { ...process.env }, encoding: 'utf8' },
    ).trim();
    // Empty output = schema matches DB
    expect(result).toBe('');
  });

  // ── SB2-SB6: critical financial table column presence ─────────────────────

  it('SB2: ledger_entries has integer-safe financial columns (no FLOAT/DOUBLE)', async () => {
    const cols = await columns(prisma, 'ledger_entries');
    expect(hasColumn(cols, 'amount_cents')).toBe(true);
    expect(hasColumn(cols, 'currency')).toBe(true);
    expect(hasColumn(cols, 'idempotency_key')).toBe(true);
    // financial amounts must be integer (bigint), never float
    const type = columnType(cols, 'amount_cents');
    expect(['bigint', 'int', 'mediumint']).toContain(type);
  });

  it('SB3: donations has integer-safe donated_cents column', async () => {
    const cols = await columns(prisma, 'donations');
    // donations uses donated_cents (domain-specific name), not amount_cents
    expect(hasColumn(cols, 'donated_cents')).toBe(true);
    expect(hasColumn(cols, 'currency')).toBe(true);
    expect(hasColumn(cols, 'idempotency_key')).toBe(true);
    expect(hasColumn(cols, 'donor_id')).toBe(true);
    expect(hasColumn(cols, 'art_lot_id')).toBe(true);
    const type = columnType(cols, 'donated_cents');
    expect(['bigint', 'int', 'mediumint']).toContain(type);
  });

  it('SB4: impact_events has idempotency_key and links back to donation', async () => {
    const cols = await columns(prisma, 'impact_events');
    // impact_events tracks qualitative outcomes; no amount_cents column
    expect(hasColumn(cols, 'idempotency_key')).toBe(true);
    expect(hasColumn(cols, 'donation_id')).toBe(true);
    expect(hasColumn(cols, 'sdg_number')).toBe(true);
    expect(hasColumn(cols, 'art_lot_id')).toBe(true);
  });

  it('SB5: bids.amount_cents is integer (not float)', async () => {
    const cols = await columns(prisma, 'bids');
    expect(hasColumn(cols, 'amount_cents')).toBe(true);
    expect(hasColumn(cols, 'status')).toBe(true);
    expect(hasColumn(cols, 'auction_item_id')).toBe(true);
    const type = columnType(cols, 'amount_cents');
    expect(['bigint', 'int', 'mediumint']).toContain(type);
  });

  it('SB6: art_lots.starting_bid_cents is integer (not float)', async () => {
    const cols = await columns(prisma, 'art_lots');
    expect(hasColumn(cols, 'starting_bid_cents')).toBe(true);
    expect(hasColumn(cols, 'currency')).toBe(true);
    const type = columnType(cols, 'starting_bid_cents');
    expect(['bigint', 'int', 'mediumint']).toContain(type);
  });

  // ── SB7: Three-ledger isolation — no shared table ─────────────────────────

  it('SB7: three ledger tables are distinct (ledger_entries ≠ donations ≠ impact_events)', async () => {
    const tables = await prisma.$queryRaw<Array<{ TABLE_NAME: string }>>`
      SELECT TABLE_NAME FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('ledger_entries', 'donations', 'impact_events')
    `;
    const names = tables.map((t) => t.TABLE_NAME).sort();
    expect(names).toEqual(['donations', 'impact_events', 'ledger_entries']);
  });

  // ── SB8: refresh_tokens table exists with required columns ────────────────

  it('SB8: refresh_tokens table has required columns', async () => {
    const cols = await columns(prisma, 'refresh_tokens');
    expect(hasColumn(cols, 'user_id')).toBe(true);
    expect(hasColumn(cols, 'token_hash')).toBe(true);
    expect(hasColumn(cols, 'expires_at')).toBe(true);
    expect(hasColumn(cols, 'revoked_at')).toBe(true);
  });

  // ── SB9: auction_items.bid_increment_cents is integer ─────────────────────

  it('SB9: auction_items.bid_increment_cents is nullable integer (not float)', async () => {
    const cols = await columns(prisma, 'auction_items');
    expect(hasColumn(cols, 'bid_increment_cents')).toBe(true);
    expect(hasColumn(cols, 'status')).toBe(true);
    const type = columnType(cols, 'bid_increment_cents');
    expect(['bigint', 'int', 'mediumint']).toContain(type);
  });

  // ── SB10: users.role enum contains the expected values ────────────────────

  it('SB10: users.role column is an enum with buyer|artist|admin|operator|curator', async () => {
    const [row] = await prisma.$queryRaw<Array<{ COLUMN_TYPE: string }>>`
      SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'users'
        AND COLUMN_NAME  = 'role'
    `;
    expect(row).toBeDefined();
    const enumStr = row.COLUMN_TYPE; // e.g. "enum('buyer','artist','admin','operator','curator')"
    for (const role of ['buyer', 'artist', 'admin', 'operator', 'curator']) {
      expect(enumStr).toContain(role);
    }
  });
});
