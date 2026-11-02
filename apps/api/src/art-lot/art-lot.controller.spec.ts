/**
 * Phase 0.3L — ArtLot public route tests
 *
 * AL1  GET /art-lots             — list with pagination
 * AL2  GET /art-lots/:artLot     — get by ID/slug
 * AL3  GET /art-lots/:artLot/bids — list accepted bids
 * AL4  GET /art-lots/:artLot/provenance — provenance chain
 * AL5  GET /art-lots?status=active — filter by status
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';
import { ArtLotController } from './art-lot.controller';
import { ArtLotService } from './art-lot.service';

const STUB_LOT = {
  id: 1, sale_mode: 'sell_now', status: 'active',
  starting_bid_cents: null, buy_now_price_cents: 150000,
  currency: 'BGN', estimate_low_cents: null, estimate_high_cents: null,
  published_at: new Date('2026-01-01').toISOString(), closed_at: null,
  created_at: new Date('2026-01-01').toISOString(),
};

const STUB_BID = {
  id: 1, amount_cents: 75000, status: 'accepted',
  bid_type: 'live', created_at: new Date('2026-04-01').toISOString(),
};

const mockArtLotService = {
  listArtLots: async () => ({
    data: [STUB_LOT],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  }),
  getArtLot: async (id: string) => {
    if (id === '999') throw new NotFoundException('ArtLot "999" not found');
    return STUB_LOT;
  },
  listBids: async () => [STUB_BID],
  getProvenance: async () => [],
};

describe('Phase 0.3L — ArtLot controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [ArtLotController],
      providers: [{ provide: ArtLotService, useValue: mockArtLotService }],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('AL1: GET /art-lots returns 200 with pagination', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/art-lots' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
    expect(body.data[0]).toHaveProperty('sale_mode');
  });

  it('AL2: GET /art-lots/:artLot returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/art-lots/1' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('id', 1);
  });

  it('AL3: GET /art-lots/:artLot/bids returns 200 with array', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/art-lots/1/bids' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body)).toBe(true);
    expect(body[0]).toHaveProperty('amount_cents');
  });

  it('AL4: GET /art-lots/:artLot/provenance returns 200 with array', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/art-lots/1/provenance' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body)).toBe(true);
  });

  it('AL5: GET /art-lots?status=active returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/art-lots?status=active' });
    expect(res.statusCode).toBe(200);
  });
});
