/**
 * Phase 0.3U — HealthController tests
 *
 * HC1  GET /health — 200 with status + db + timestamp
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { INestApplication } from '@nestjs/common';
import { HealthController } from './health.controller';
import { AuctionService } from './auction/auction.service';

const mockAuctionService = {
  health: async () => ({ status: 'ok', db: 'ok', timestamp: new Date().toISOString() }),
};

describe('Phase 0.3U — HealthController', () => {
  let app: INestApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [HealthController],
      providers: [{ provide: AuctionService, useValue: mockAuctionService }],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await (app as NestFastifyApplication).getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('HC1: GET /health returns 200 with status, db and timestamp', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/health' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.status).toBe('ok');
    expect(body.db).toBe('ok');
    expect(body).toHaveProperty('timestamp');
  });
});
