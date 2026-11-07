/**
 * Phase 0.3N — DonationRecipient public route tests
 *
 * DR1  GET /donation-recipients       — list (200)
 * DR2  GET /donation-recipients/:id   — get one (200)
 * DR3  GET /donation-recipients/999   — 404
 * DR4  POST /donation-recipients      — 201 with created recipient
 * DR5  PATCH /donation-recipients/:id — 200 with updated recipient
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';
import { DonationRecipientController } from './donation-recipient.controller';
import { DonationRecipientService } from './donation-recipient.service';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';

const mockJwtGuard = { canActivate: () => true };

const STUB_RECIPIENT = {
  id: 1, name: 'Sofia Art Foundation', legal_type: 'ngo',
  eligibility_basis: 'public_benefit', deduction_bps: 500,
  status: 'active', created_at: new Date('2025-01-01').toISOString(),
};

const mockDonationRecipientService = {
  listRecipients: async () => ({
    data: [STUB_RECIPIENT],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  }),
  getRecipient: async (id: number) => {
    if (id === 999) throw new NotFoundException(`DonationRecipient ${id} not found`);
    return STUB_RECIPIENT;
  },
  createRecipient: async (dto: any) => ({ ...STUB_RECIPIENT, ...dto }),
  updateRecipient: async (_id: number, dto: any) => ({ ...STUB_RECIPIENT, ...dto, updated_at: new Date().toISOString() }),
};

describe('Phase 0.3N — DonationRecipient controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [DonationRecipientController],
      providers: [{ provide: DonationRecipientService, useValue: mockDonationRecipientService }],
    })
      .overrideGuard(JwtAuthGuard)
      .useValue(mockJwtGuard)
      .compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('DR1: GET /donation-recipients returns 200 with pagination', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/donation-recipients' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body.data[0]).toHaveProperty('eligibility_basis');
  });

  it('DR2: GET /donation-recipients/:id returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/donation-recipients/1' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('name', 'Sofia Art Foundation');
  });

  it('DR3: GET /donation-recipients/999 returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/donation-recipients/999' });
    expect(res.statusCode).toBe(404);
  });

  it('DR4: POST /donation-recipients returns 201 with created recipient', async () => {
    const body = { name: 'New Foundation', legal_type: 'ngo' };
    const res = await app.inject({ method: 'POST', url: '/api/v1/donation-recipients', payload: body });
    expect(res.statusCode).toBe(201);
    const resBody = JSON.parse(res.payload);
    expect(resBody).toHaveProperty('name', 'New Foundation');
  });

  it('DR5: PATCH /donation-recipients/1 returns 200 with updated recipient', async () => {
    const res = await app.inject({ method: 'PATCH', url: '/api/v1/donation-recipients/1', payload: { deduction_bps: 1000 } });
    expect(res.statusCode).toBe(200);
    const resBody = JSON.parse(res.payload);
    expect(resBody).toHaveProperty('deduction_bps', 1000);
  });
});
