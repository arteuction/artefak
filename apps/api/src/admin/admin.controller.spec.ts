/**
 * Phase 0.3U — AdminController stub tests
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

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { INestApplication } from '@nestjs/common';
import { AdminController } from './admin.controller';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';

const token = 'Bearer test';

const mockJwtGuard = { canActivate: () => true };

describe('Phase 0.3U — AdminController', () => {
  let app: INestApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [AdminController],
    })
      .overrideGuard(JwtAuthGuard)
      .useValue(mockJwtGuard)
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
    const res = await (app as NestFastifyApplication).inject({ method: 'POST', url: '/api/v1/admin/auctions', headers: { authorization: token }, payload: {} });
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
