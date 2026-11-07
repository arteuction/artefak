/**
 * Phase 0.3U — Misc stub controllers tests
 *
 * EV1  GET  /evidence               — 200
 * EV2  GET  /evidence/1            — 200 (show)
 * EV3  POST /evidence/1/verify      — 200 { verified: true }
 * AC1  GET  /api-clients            — 200
 * AC2  POST /api-clients            — 201
 * AC3  DELETE /api-clients/1        — 200 { deleted: true }
 * PT1  POST /partners/onboard       — 201 { onboarded: true }
 * DE1  GET  /domain-events          — 200
 * SD1  POST /sdg-claims/1/impact-events — 201 { recorded: true }
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { INestApplication } from '@nestjs/common';
import { EvidenceController, ApiClientController, PartnerController, DomainEventController, SdgClaimImpactController } from './misc.controller';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';

const mockJwtGuard = { canActivate: () => true };

describe('Phase 0.3U — Misc stub controllers', () => {
  let app: INestApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [EvidenceController, ApiClientController, PartnerController, DomainEventController, SdgClaimImpactController],
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

  it('EV1: GET /evidence returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/evidence' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
  });

  it('EV2: GET /evidence/1 returns 200 (show)', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/evidence/1' });
    expect(res.statusCode).toBe(200);
  });

  it('EV3: POST /evidence/1/verify returns 200 with verified flag', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'POST', url: '/api/v1/evidence/1/verify', payload: {} });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.verified).toBe(true);
  });

  it('AC1: GET /api-clients returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/api-clients' });
    expect(res.statusCode).toBe(200);
  });

  it('AC2: POST /api-clients returns 201', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'POST', url: '/api/v1/api-clients', payload: {} });
    expect(res.statusCode).toBe(201);
  });

  it('AC3: DELETE /api-clients/1 returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'DELETE', url: '/api/v1/api-clients/1' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.deleted).toBe(true);
  });

  it('PT1: POST /partners/onboard returns 201', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'POST', url: '/api/v1/partners/onboard', payload: { name: 'Partner X' } });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body.onboarded).toBe(true);
  });

  it('DE1: GET /domain-events returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/domain-events' });
    expect(res.statusCode).toBe(200);
  });

  it('SD1: POST /sdg-claims/1/impact-events returns 201', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'POST', url: '/api/v1/sdg-claims/1/impact-events', payload: { value: 42 } });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body.recorded).toBe(true);
  });

});
