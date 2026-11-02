/**
 * Phase 0.3O — ImpactProject public route tests
 *
 * IP1  GET /impact-projects/stats  — stats (200)
 * IP2  GET /impact-projects        — list (200)
 * IP3  GET /impact-projects/:id    — get one (200)
 * IP4  GET /impact-projects/999    — 404
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';
import { ImpactProjectController } from './impact-project.controller';
import { ImpactProjectService } from './impact-project.service';

const STUB_PROJECT = {
  id: 1, title: 'Forest Restoration Fund', slug: 'forest-restoration',
  sdg_number: 15, funding_target_cents: 1000000, funding_actual_cents: 250000,
  currency: 'BGN', status: 'active', starts_on: '2026-01-01', ends_on: '2026-12-31',
  created_at: new Date('2026-01-01').toISOString(),
};

const mockImpactProjectService = {
  getStats: async () => ({ total: 5, funded: 2 }),
  listProjects: async () => ({
    data: [STUB_PROJECT],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  }),
  getProject: async (id: string) => {
    if (id === '999') throw new NotFoundException(`ImpactProject "${id}" not found`);
    return STUB_PROJECT;
  },
};

describe('Phase 0.3O — ImpactProject controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [ImpactProjectController],
      providers: [{ provide: ImpactProjectService, useValue: mockImpactProjectService }],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('IP1: GET /impact-projects/stats returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/impact-projects/stats' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('total');
    expect(body).toHaveProperty('funded');
  });

  it('IP2: GET /impact-projects returns 200 with pagination', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/impact-projects' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body.data[0]).toHaveProperty('sdg_number');
  });

  it('IP3: GET /impact-projects/:id returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/impact-projects/1' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('slug', 'forest-restoration');
  });

  it('IP4: GET /impact-projects/999 returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/impact-projects/999' });
    expect(res.statusCode).toBe(404);
  });
});
