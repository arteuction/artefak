/**
 * Phase 0.3U — AdminController tests
 *
 * AD1  GET  /admin/audit-log          — 200 + pagination
 * AD2  POST /admin/reconciliation-runs — 201
 * AD3  GET  /admin/users              — 200 + pagination
 * AD4  POST /admin/auctions           — 201
 * AD5  POST /admin/auctions/:id/close — 200
 * AD6  GET  /admin/rulesets           — 200
 * AD7  POST /admin/split-profiles     — 201
 * AD8  GET  /admin/ledger             — 200
 * AD9  POST /admin/books              — 201
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
  publishAuction: jest.fn() as any,
  openAuction: jest.fn() as any,
  closeAuction: jest.fn() as any,
};

describe('Phase 0.3U — AdminController', () => {
  let app: INestApplication;

  beforeAll(async () => {
    mockAdminService.listAuditLog.mockResolvedValue(paged());
    mockAdminService.listUsers.mockResolvedValue(paged());
    mockAdminService.listLedger.mockResolvedValue(paged());
    mockAdminService.createAuction.mockResolvedValue({ id: 1, title: 'Test', slug: 'test', status: 'draft' });
    mockAdminService.closeAuction.mockResolvedValue({ id: 1, status: 'closed' });

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

  it('AD1: GET /admin/audit-log returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/admin/audit-log', headers: { authorization: token } });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
  });

  it('AD2: POST /admin/reconciliation-runs returns 201', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'POST', url: '/api/v1/admin/reconciliation-runs', headers: { authorization: token }, payload: {} });
    expect(res.statusCode).toBe(201);
  });

  it('AD3: GET /admin/users returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/admin/users', headers: { authorization: token } });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
  });

  it('AD4: POST /admin/auctions returns 201', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'POST', url: '/api/v1/admin/auctions', headers: { authorization: token }, payload: { title: 'Test', slug: 'test', starts_at: '2027-01-01T00:00:00Z', ends_at: '2027-01-07T00:00:00Z' } });
    expect(res.statusCode).toBe(201);
  });

  it('AD5: POST /admin/auctions/1/close returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'POST', url: '/api/v1/admin/auctions/1/close', headers: { authorization: token }, payload: {} });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('status');
  });

  it('AD6: GET /admin/rulesets returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/admin/rulesets', headers: { authorization: token } });
    expect(res.statusCode).toBe(200);
  });

  it('AD7: POST /admin/split-profiles returns 201', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'POST', url: '/api/v1/admin/split-profiles', headers: { authorization: token }, payload: {} });
    expect(res.statusCode).toBe(201);
  });

  it('AD8: GET /admin/ledger returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/admin/ledger', headers: { authorization: token } });
    expect(res.statusCode).toBe(200);
  });

  it('AD9: POST /admin/books returns 201', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'POST', url: '/api/v1/admin/books', headers: { authorization: token }, payload: {} });
    expect(res.statusCode).toBe(201);
  });
});
