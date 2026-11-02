/**
 * Phase 0.3U — ArtMetro legacy stub controller tests
 *
 * AL1  GET  /artmetro/artifacts/QR123        — 200
 * AL2  POST /artmetro/artifacts/QR123/scans  — 201
 * AL3  POST /artmetro/artifacts/1/visit      — 200
 * AL4  GET  /artmetro/routes/route-slug      — 200
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { INestApplication } from '@nestjs/common';
import { ArtifactLegacyController, ArtmetroRouteLegacyController } from './artmetro-legacy.controller';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';

const mockJwtGuard = { canActivate: () => true };

describe('Phase 0.3U — ArtMetro legacy controllers', () => {
  let app: INestApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [ArtifactLegacyController, ArtmetroRouteLegacyController],
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

  it('AL1: GET /artmetro/artifacts/QR123 returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/artmetro/artifacts/QR123' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('status');
  });

  it('AL2: POST /artmetro/artifacts/QR123/scans returns 201', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'POST', url: '/api/v1/artmetro/artifacts/QR123/scans', payload: {} });
    expect(res.statusCode).toBe(201);
  });

  it('AL3: POST /artmetro/artifacts/1/visit returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'POST', url: '/api/v1/artmetro/artifacts/1/visit', payload: {} });
    expect(res.statusCode).toBe(200);
  });

  it('AL4: GET /artmetro/routes/route-slug returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/artmetro/routes/route-slug' });
    expect(res.statusCode).toBe(200);
  });
});
