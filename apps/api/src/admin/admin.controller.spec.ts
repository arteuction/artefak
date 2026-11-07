/**
 * Phase 0.3U — AdminController tests
 *
 * AD1   GET    /admin/audit-log                              — 200
 * AD2   GET    /admin/audit-log/:id                         — 200
 * AD3   GET    /admin/settlements                           — 200
 * AD4   GET    /admin/settlements/:id                       — 200
 * AD5   GET    /admin/settlements/:id/statement             — 200
 * AD6   GET    /admin/reconciliation-runs                   — 200
 * AD7   GET    /admin/reconciliation-runs/:id               — 200
 * AD8   POST   /admin/reconciliation-runs                   — 201
 * AD9   GET    /admin/reconciliation-mismatches             — 200
 * AD10  GET    /admin/reconciliation-mismatches/:id         — 200
 * AD11  PATCH  /admin/reconciliation-mismatches/:id         — 200
 * AD12  POST   /admin/artmetro/routes                       — 201
 * AD13  PATCH  /admin/artmetro/routes/:route                — 200
 * AD14  PATCH  /admin/artmetro/routes/:route/publish        — 200
 * AD15  POST   /admin/artmetro/routes/:route/stops          — 201
 * AD16  DELETE /admin/artmetro/routes/:route/stops/:stop    — 200
 * AD17  GET    /admin/users                                 — 200
 * AD18  GET    /admin/users/:user                           — 200
 * AD19  PATCH  /admin/users/:user                           — 200
 * AD20  GET    /admin/ledger                                — 200
 * AD21  GET    /admin/refunds                               — 200
 * AD22  GET    /admin/split-profiles                        — 200
 * AD23  POST   /admin/split-profiles                        — 201
 * AD24  PATCH  /admin/split-profiles/:id/deprecate          — 200
 * AD25  GET    /admin/settlement-lines                      — 200
 * AD26  POST   /admin/settlement-lines/:id/disburse         — 200
 * AD27  POST   /admin/books                                 — 201
 * AD28  PATCH  /admin/books/:book                           — 200
 * AD29  POST   /admin/book-categories                       — 201
 * AD30  PATCH  /admin/book-categories/:category             — 200
 * AD31  DELETE /admin/book-categories/:category             — 200
 * AD32  GET    /admin/books/:book/reviews/pending           — 200
 * AD33  PATCH  /admin/books/:book/reviews/:review/approve   — 200
 * AD34  DELETE /admin/books/:book/reviews/:review           — 200
 * AD35  GET    /admin/rulesets                              — 200
 * AD36  GET    /admin/rulesets/:ruleset                     — 200
 * AD37  POST   /admin/rulesets                              — 201
 * AD38  PATCH  /admin/rulesets/:ruleset                     — 200
 * AD39  GET    /admin/transfer-outbox                       — 200
 * AD40  GET    /admin/transfer-outbox/:id                   — 200
 * AD41  GET    /admin/connected-payouts                     — 200
 * AD42  GET    /admin/connected-payouts/:id                 — 200
 * AD43  POST   /admin/auctions                              — 201
 * AD44  PATCH  /admin/auctions/:auction                     — 200
 * AD45  POST   /admin/auctions/:auction/items               — 201
 * AD46  DELETE /admin/auctions/:auction/items/:item         — 200
 * AD47  POST   /admin/auctions/:auction/publish             — 200
 * AD48  POST   /admin/auctions/:auction/open                — 200
 * AD49  POST   /admin/auctions/:auction/close               — 200
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { INestApplication } from '@nestjs/common';
import { AdminController } from './admin.controller';
import { AdminService } from './admin.service';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { RolesGuard } from '../auth/roles.guard';

const token = 'Bearer test';
const mockJwtGuard = { canActivate: () => true };
const mockRolesGuard = { canActivate: () => true };

const paged = (data: unknown[] = []) => ({ data, meta: { total: 0, current_page: 1, last_page: 1, per_page: 20 } });

const STUB_AUCTION = { id: 1, title: 'Test Auction', slug: 'test-auction', status: 'draft', starts_at: '2027-01-01T00:00:00Z', ends_at: '2027-01-07T00:00:00Z' };
const STUB_ITEM = { id: 10, auction_id: 1, art_lot_id: 5, lot_number: 1, status: 'pending' };
const STUB_SETTLEMENT = { id: 1, status: 'pending', total_cents: 100000, currency: 'EUR' };
const STUB_USER = { id: 1, email: 'user@example.com', role: 'buyer', status: 'active' };

const mockAdminService = {
  listAuditLog: jest.fn() as any,
  listSettlements: jest.fn() as any,
  getSettlement: jest.fn() as any,
  listUsers: jest.fn() as any,
  getUser: jest.fn() as any,
  updateUser: jest.fn() as any,
  listLedger: jest.fn() as any,
  listRefunds: jest.fn() as any,
  listSettlementLines: jest.fn() as any,
  disburseSettlementLine: jest.fn() as any,
  listTransferOutbox: jest.fn() as any,
  getTransferOutboxItem: jest.fn() as any,
  createAuction: jest.fn() as any,
  updateAuction: jest.fn() as any,
  addAuctionItem: jest.fn() as any,
  removeAuctionItem: jest.fn() as any,
  publishAuction: jest.fn() as any,
  openAuction: jest.fn() as any,
  closeAuction: jest.fn() as any,
};

describe('Phase 0.3U — AdminController', () => {
  let app: INestApplication;

  beforeAll(async () => {
    mockAdminService.listAuditLog.mockResolvedValue(paged());
    mockAdminService.listSettlements.mockResolvedValue(paged());
    mockAdminService.getSettlement.mockResolvedValue(STUB_SETTLEMENT);
    mockAdminService.listUsers.mockResolvedValue(paged());
    mockAdminService.getUser.mockResolvedValue(STUB_USER);
    mockAdminService.updateUser.mockResolvedValue({ ...STUB_USER, role: 'operator' });
    mockAdminService.listLedger.mockResolvedValue(paged());
    mockAdminService.listRefunds.mockResolvedValue(paged());
    mockAdminService.listSettlementLines.mockResolvedValue(paged());
    mockAdminService.disburseSettlementLine.mockResolvedValue({ id: 1, status: 'disbursed' });
    mockAdminService.listTransferOutbox.mockResolvedValue(paged());
    mockAdminService.getTransferOutboxItem.mockResolvedValue({ id: 1, status: 'pending' });
    mockAdminService.createAuction.mockResolvedValue(STUB_AUCTION);
    mockAdminService.updateAuction.mockResolvedValue({ ...STUB_AUCTION, title: 'Updated' });
    mockAdminService.addAuctionItem.mockResolvedValue(STUB_ITEM);
    mockAdminService.removeAuctionItem.mockResolvedValue({ deleted: true });
    mockAdminService.publishAuction.mockResolvedValue({ ...STUB_AUCTION, status: 'published' });
    mockAdminService.openAuction.mockResolvedValue({ ...STUB_AUCTION, status: 'live' });
    mockAdminService.closeAuction.mockResolvedValue({ ...STUB_AUCTION, status: 'closed' });

    const module: TestingModule = await Test.createTestingModule({
      controllers: [AdminController],
      providers: [{ provide: AdminService, useValue: mockAdminService }],
    })
      .overrideGuard(JwtAuthGuard).useValue(mockJwtGuard)
      .overrideGuard(RolesGuard).useValue(mockRolesGuard)
      .compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await (app as NestFastifyApplication).getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  const inject = (method: string, url: string, payload?: object) =>
    (app as NestFastifyApplication).inject({ method, url: `/api/v1/admin/${url}`, headers: { authorization: token }, payload });

  // ── Audit log ─────────────────────────────────────────────────────────────────
  it('AD1: GET /admin/audit-log returns 200', async () => {
    const res = await inject('GET', 'audit-log');
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('data');
  });
  it('AD2: GET /admin/audit-log/:id returns 200 (inline stub)', async () => {
    const res = await inject('GET', 'audit-log/1');
    expect(res.statusCode).toBe(200);
  });

  // ── Settlements ───────────────────────────────────────────────────────────────
  it('AD3: GET /admin/settlements returns 200', async () => {
    const res = await inject('GET', 'settlements');
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('data');
  });
  it('AD4: GET /admin/settlements/:id returns 200', async () => {
    const res = await inject('GET', 'settlements/1');
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('status');
  });
  it('AD5: GET /admin/settlements/:id/statement returns 200 (inline stub)', async () => {
    const res = await inject('GET', 'settlements/1/statement');
    expect(res.statusCode).toBe(200);
  });

  // ── Reconciliation ────────────────────────────────────────────────────────────
  it('AD6: GET /admin/reconciliation-runs returns 200 (inline stub)', async () => {
    const res = await inject('GET', 'reconciliation-runs');
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('data');
  });
  it('AD7: GET /admin/reconciliation-runs/:id returns 200 (inline stub)', async () => {
    const res = await inject('GET', 'reconciliation-runs/1');
    expect(res.statusCode).toBe(200);
  });
  it('AD8: POST /admin/reconciliation-runs returns 201 (inline stub)', async () => {
    const res = await inject('POST', 'reconciliation-runs', {});
    expect(res.statusCode).toBe(201);
  });
  it('AD9: GET /admin/reconciliation-mismatches returns 200 (inline stub)', async () => {
    const res = await inject('GET', 'reconciliation-mismatches');
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('data');
  });
  it('AD10: GET /admin/reconciliation-mismatches/:id returns 200 (inline stub)', async () => {
    const res = await inject('GET', 'reconciliation-mismatches/1');
    expect(res.statusCode).toBe(200);
  });
  it('AD11: PATCH /admin/reconciliation-mismatches/:id returns 200 (inline stub)', async () => {
    const res = await inject('PATCH', 'reconciliation-mismatches/1', {});
    expect(res.statusCode).toBe(200);
  });

  // ── Artmetro ──────────────────────────────────────────────────────────────────
  it('AD12: POST /admin/artmetro/routes returns 201 (inline stub)', async () => {
    const res = await inject('POST', 'artmetro/routes', {});
    expect(res.statusCode).toBe(201);
  });
  it('AD13: PATCH /admin/artmetro/routes/:route returns 200 (inline stub)', async () => {
    const res = await inject('PATCH', 'artmetro/routes/1', {});
    expect(res.statusCode).toBe(200);
  });
  it('AD14: PATCH /admin/artmetro/routes/:route/publish returns 200 (inline stub)', async () => {
    const res = await inject('PATCH', 'artmetro/routes/1/publish', {});
    expect(res.statusCode).toBe(200);
  });
  it('AD15: POST /admin/artmetro/routes/:route/stops returns 201 (inline stub)', async () => {
    const res = await inject('POST', 'artmetro/routes/1/stops', {});
    expect(res.statusCode).toBe(201);
  });
  it('AD16: DELETE /admin/artmetro/routes/:route/stops/:stop returns 200 (inline stub)', async () => {
    const res = await inject('DELETE', 'artmetro/routes/1/stops/2');
    expect(res.statusCode).toBe(200);
  });

  // ── Users ─────────────────────────────────────────────────────────────────────
  it('AD17: GET /admin/users returns 200', async () => {
    const res = await inject('GET', 'users');
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('data');
  });
  it('AD18: GET /admin/users/:user returns 200', async () => {
    const res = await inject('GET', 'users/1');
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('email');
  });
  it('AD19: PATCH /admin/users/:user returns 200', async () => {
    const res = await inject('PATCH', 'users/1', { role: 'operator' });
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('role');
  });

  // ── Ledger & Finance ──────────────────────────────────────────────────────────
  it('AD20: GET /admin/ledger returns 200', async () => {
    const res = await inject('GET', 'ledger');
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('data');
  });
  it('AD21: GET /admin/refunds returns 200', async () => {
    const res = await inject('GET', 'refunds');
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('data');
  });
  it('AD22: GET /admin/split-profiles returns 200 (inline stub)', async () => {
    const res = await inject('GET', 'split-profiles');
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('data');
  });
  it('AD23: POST /admin/split-profiles returns 201 (inline stub)', async () => {
    const res = await inject('POST', 'split-profiles', {});
    expect(res.statusCode).toBe(201);
  });
  it('AD24: PATCH /admin/split-profiles/:id/deprecate returns 200 (inline stub)', async () => {
    const res = await inject('PATCH', 'split-profiles/1/deprecate', {});
    expect(res.statusCode).toBe(200);
  });
  it('AD25: GET /admin/settlement-lines returns 200', async () => {
    const res = await inject('GET', 'settlement-lines');
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('data');
  });
  it('AD26: POST /admin/settlement-lines/:id/disburse returns 200', async () => {
    const res = await inject('POST', 'settlement-lines/1/disburse', {});
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('status', 'disbursed');
  });

  // ── Books ─────────────────────────────────────────────────────────────────────
  it('AD27: POST /admin/books returns 201 (inline stub)', async () => {
    const res = await inject('POST', 'books', {});
    expect(res.statusCode).toBe(201);
  });
  it('AD28: PATCH /admin/books/:book returns 200 (inline stub)', async () => {
    const res = await inject('PATCH', 'books/1', {});
    expect(res.statusCode).toBe(200);
  });
  it('AD29: POST /admin/book-categories returns 201 (inline stub)', async () => {
    const res = await inject('POST', 'book-categories', {});
    expect(res.statusCode).toBe(201);
  });
  it('AD30: PATCH /admin/book-categories/:category returns 200 (inline stub)', async () => {
    const res = await inject('PATCH', 'book-categories/1', {});
    expect(res.statusCode).toBe(200);
  });
  it('AD31: DELETE /admin/book-categories/:category returns 200 (inline stub)', async () => {
    const res = await inject('DELETE', 'book-categories/1');
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('status', 'ok');
  });
  it('AD32: GET /admin/books/:book/reviews/pending returns 200 (inline stub)', async () => {
    const res = await inject('GET', 'books/1/reviews/pending');
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('data');
  });
  it('AD33: PATCH /admin/books/:book/reviews/:review/approve returns 200 (inline stub)', async () => {
    const res = await inject('PATCH', 'books/1/reviews/2/approve', {});
    expect(res.statusCode).toBe(200);
  });
  it('AD34: DELETE /admin/books/:book/reviews/:review returns 200 (inline stub)', async () => {
    const res = await inject('DELETE', 'books/1/reviews/2');
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('status', 'ok');
  });

  // ── Rulesets ──────────────────────────────────────────────────────────────────
  it('AD35: GET /admin/rulesets returns 200 (inline stub)', async () => {
    const res = await inject('GET', 'rulesets');
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('data');
  });
  it('AD36: GET /admin/rulesets/:ruleset returns 200 (inline stub)', async () => {
    const res = await inject('GET', 'rulesets/1');
    expect(res.statusCode).toBe(200);
  });
  it('AD37: POST /admin/rulesets returns 201 (inline stub)', async () => {
    const res = await inject('POST', 'rulesets', {});
    expect(res.statusCode).toBe(201);
  });
  it('AD38: PATCH /admin/rulesets/:ruleset returns 200 (inline stub)', async () => {
    const res = await inject('PATCH', 'rulesets/1', {});
    expect(res.statusCode).toBe(200);
  });

  // ── Transfer Outbox ───────────────────────────────────────────────────────────
  it('AD39: GET /admin/transfer-outbox returns 200', async () => {
    const res = await inject('GET', 'transfer-outbox');
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('data');
  });
  it('AD40: GET /admin/transfer-outbox/:id returns 200', async () => {
    const res = await inject('GET', 'transfer-outbox/1');
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('status');
  });

  // ── Connected Payouts ─────────────────────────────────────────────────────────
  it('AD41: GET /admin/connected-payouts returns 200 (inline stub)', async () => {
    const res = await inject('GET', 'connected-payouts');
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('data');
  });
  it('AD42: GET /admin/connected-payouts/:id returns 200 (inline stub)', async () => {
    const res = await inject('GET', 'connected-payouts/1');
    expect(res.statusCode).toBe(200);
  });

  // ── Auctions ──────────────────────────────────────────────────────────────────
  it('AD43: POST /admin/auctions returns 201', async () => {
    const res = await inject('POST', 'auctions', { title: 'Test', slug: 'test', starts_at: '2027-01-01T00:00:00Z', ends_at: '2027-01-07T00:00:00Z' });
    expect(res.statusCode).toBe(201);
    expect(JSON.parse(res.payload)).toHaveProperty('status', 'draft');
  });
  it('AD44: PATCH /admin/auctions/:auction returns 200', async () => {
    const res = await inject('PATCH', 'auctions/1', { title: 'Updated' });
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('title', 'Updated');
  });
  it('AD45: POST /admin/auctions/:auction/items returns 201', async () => {
    const res = await inject('POST', 'auctions/1/items', { art_lot_id: 5, lot_number: 1 });
    expect(res.statusCode).toBe(201);
    expect(JSON.parse(res.payload)).toHaveProperty('auction_id', 1);
  });
  it('AD46: DELETE /admin/auctions/:auction/items/:item returns 200', async () => {
    const res = await inject('DELETE', 'auctions/1/items/10');
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('deleted', true);
  });
  it('AD47: POST /admin/auctions/:auction/publish returns 200', async () => {
    const res = await inject('POST', 'auctions/1/publish', {});
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('status', 'published');
  });
  it('AD48: POST /admin/auctions/:auction/open returns 200', async () => {
    const res = await inject('POST', 'auctions/1/open', {});
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('status', 'live');
  });
  it('AD49: POST /admin/auctions/:auction/close returns 200', async () => {
    const res = await inject('POST', 'auctions/1/close', {});
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('status', 'closed');
  });
});
