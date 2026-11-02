/**
 * Phase 0.3U — OpsController stub tests
 *
 * OP1  GET  /ops/summary            — 200
 * OP2  GET  /ops/pending-events     — 200
 * OP3  GET  /ops/failed-outbox      — 200
 * OP4  GET  /ops/consumer-lag       — 200
 * OP5  GET  /ops/fiscal-year/2026   — 200
 * OP6  POST /ops/transfer-outbox/1/retry — 200 { retried: true }
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { INestApplication } from '@nestjs/common';
import { OpsController } from './ops.controller';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';

const mockJwtGuard = { canActivate: () => true };

describe('Phase 0.3U — OpsController', () => {
  let app: INestApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [OpsController],
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

  it('OP1: GET /ops/summary returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/ops/summary' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
  });

  it('OP2: GET /ops/pending-events returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/ops/pending-events' });
    expect(res.statusCode).toBe(200);
  });

  it('OP3: GET /ops/failed-outbox returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/ops/failed-outbox' });
    expect(res.statusCode).toBe(200);
  });

  it('OP4: GET /ops/consumer-lag returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/ops/consumer-lag' });
    expect(res.statusCode).toBe(200);
  });

  it('OP5: GET /ops/fiscal-year/2026 returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/ops/fiscal-year/2026' });
    expect(res.statusCode).toBe(200);
  });

  it('OP6: POST /ops/transfer-outbox/1/retry returns 200 with retried flag', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'POST', url: '/api/v1/ops/transfer-outbox/1/retry', payload: {} });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.retried).toBe(true);
  });
});
