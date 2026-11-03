/**
 * Phase 0.3U â€” ImpactProjectService unit tests
 *
 * IP1  getStats â€” returns total and funded counts
 * IP2  listProjects default pagination
 * IP3  getProject by slug â€” found
 * IP4  getProject by slug â€” not found: throws NotFoundException
 * IP5  getProject by numeric id — found
 * IP6  createProject — writes to impact_projects table
 * IP7  updateProject — found: persists status change
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { ImpactProjectService } from './impact-project.service';
import { PrismaService } from '../prisma.service';

const STUB = {
  id: BigInt(1), title: 'Reforestation BG', slug: 'reforestation-bg', sdg_number: 15,
  funding_target_cents: BigInt(100000), funding_actual_cents: BigInt(50000),
  currency: 'BGN', status: 'active', starts_on: new Date(), ends_on: new Date(), created_at: new Date(),
};

const fn = () => jest.fn() as any;
const mockPrisma = { impact_projects: { findMany: fn(), findFirst: fn(), count: fn(), create: fn(), update: fn() }, $transaction: fn() };

describe('Phase 0.3U â€” ImpactProjectService', () => {
  let svc: ImpactProjectService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [ImpactProjectService, { provide: PrismaService, useValue: mockPrisma }],
    }).compile();
    svc = module.get(ImpactProjectService);
  });

  beforeEach(() => { jest.clearAllMocks(); });

  it('IP1: getStats â€” returns total and funded counts', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([10, 3]);
    const result = await svc.getStats();
    expect(result.total).toBe(10);
    expect(result.funded).toBe(3);
  });

  it('IP2: listProjects default pagination', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[STUB], 1]);
    const result = await svc.listProjects();
    expect(result.data).toHaveLength(1);
    expect(result.meta.current_page).toBe(1);
  });

  it('IP3: getProject by slug â€” found', async () => {
    mockPrisma.impact_projects.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getProject('reforestation-bg');
    expect(result.slug).toBe('reforestation-bg');
  });

  it('IP4: getProject by slug â€” not found: throws NotFoundException', async () => {
    mockPrisma.impact_projects.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getProject('missing')).rejects.toThrow(NotFoundException);
  });

  it('IP5: getProject by numeric id â€” found', async () => {
    mockPrisma.impact_projects.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getProject('1');
    expect(result.title).toBe('Reforestation BG');
  });

  it('IP6: createProject — writes to impact_projects table', async () => {
    const created = { id: BigInt(2), title: 'Tree Planting', slug: 'tree-planting', sdg_number: 15, funding_target_cents: BigInt(50000), currency: 'EUR', status: 'active', created_at: new Date() };
    mockPrisma.impact_projects.create.mockResolvedValueOnce(created);
    const result = await svc.createProject({ title: 'Tree Planting', slug: 'tree-planting', sdg_number: 15, funding_target_cents: 50000 });
    expect(mockPrisma.impact_projects.create).toHaveBeenCalledTimes(1);
    const arg = mockPrisma.impact_projects.create.mock.calls[0][0] as any;
    expect(arg.data.slug).toBe('tree-planting');
    expect(arg.data.funding_target_cents).toEqual(BigInt(50000));
    expect(arg.data.currency).toBe('EUR');
    expect(result.title).toBe('Tree Planting');
  });

  it('IP7: updateProject — found: persists status change', async () => {
    mockPrisma.impact_projects.findFirst.mockResolvedValueOnce(STUB);
    const updated = { id: BigInt(1), title: 'Reforestation BG', slug: 'reforestation-bg', status: 'funded', updated_at: new Date() };
    mockPrisma.impact_projects.update.mockResolvedValueOnce(updated);
    const result = await svc.updateProject('reforestation-bg', { status: 'funded' });
    expect(mockPrisma.impact_projects.update).toHaveBeenCalledTimes(1);
    const arg = mockPrisma.impact_projects.update.mock.calls[0][0] as any;
    expect(arg.where.id).toEqual(BigInt(1));
    expect(arg.data.status).toBe('funded');
    expect(result.status).toBe('funded');
  });
});
