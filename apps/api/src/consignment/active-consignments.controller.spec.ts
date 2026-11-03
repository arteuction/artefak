/**
 * Phase 0.3V — ActiveConsignmentsController unit tests
 *
 * AC1  GET /active-consignments — returns paged consignments
 * AC2  GET /active-consignments?page=2&per_page=5 — respects pagination params
 * AC3  GET /active-consignments — empty result: last_page=1, total=0
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { ActiveConsignmentsController } from './active-consignments.controller';
import { PrismaService } from '../prisma.service';

const STUB = {
  id: 1, commission_bps: 0, status: 'active',
  starts_at: null, ends_at: null, created_at: new Date().toISOString(),
  artworks: { id: 1, title: 'Test', slug: 'test' },
  galleries: null,
};

const fn = () => jest.fn() as any;
const mockPrisma = {
  consignments: { findMany: fn(), count: fn() },
  $transaction: fn(),
};

describe('Phase 0.3V — ActiveConsignmentsController', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [ActiveConsignmentsController],
      providers: [{ provide: PrismaService, useValue: mockPrisma }],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  beforeEach(() => { jest.resetAllMocks(); });

  afterAll(async () => { await app.close(); });

  it('AC1: GET /active-consignments — returns paged result', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[STUB], 1]);
    const res = await app.inject({ method: 'GET', url: '/active-consignments' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
    expect(body.meta.current_page).toBe(1);
  });

  it('AC2: GET /active-consignments?page=2&per_page=5 — respects pagination', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const res = await app.inject({ method: 'GET', url: '/active-consignments?page=2&per_page=5' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.meta.current_page).toBe(2);
    expect(body.meta.per_page).toBe(5);
  });

  it('AC3: GET /active-consignments with no results — last_page=1, total=0', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const res = await app.inject({ method: 'GET', url: '/active-consignments' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.data).toHaveLength(0);
    expect(body.meta.last_page).toBe(1);
    expect(body.meta.total).toBe(0);
  });
});
