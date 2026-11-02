/**
 * Phase 0.3V — ExhibitionService unit tests
 *
 * EX1  listExhibitions default pagination
 * EX2  getExhibition by numeric id — found
 * EX3  getExhibition by slug — found
 * EX4  getExhibition — not found: throws NotFoundException
 * EX5  createExhibition — delegates to prisma.create
 * EX6  updateExhibition — found: delegates to prisma.update
 * EX7  tagSdgs — replaces SDG tags
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { ExhibitionService } from './exhibition.service';
import { PrismaService } from '../prisma.service';

const STUB = {
  id: BigInt(1), title: 'Test Exhibition', slug: 'test-exhibition',
  description: null, starts_at: new Date('2025-01-01'), ends_at: new Date('2025-03-31'),
  is_active: true, venue_id: BigInt(1), created_at: new Date(),
};

const fn = () => jest.fn() as any;
const mockPrisma = {
  exhibitions: { findMany: fn(), findFirst: fn(), count: fn(), create: fn(), update: fn() },
  artmetro_exhibition_sdg: { deleteMany: fn(), createMany: fn() },
  $transaction: fn(),
};

describe('Phase 0.3V — ExhibitionService', () => {
  let svc: ExhibitionService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [ExhibitionService, { provide: PrismaService, useValue: mockPrisma }],
    }).compile();
    svc = module.get(ExhibitionService);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  it('EX1: listExhibitions default pagination', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[STUB], 1]);
    const result = await svc.listExhibitions();
    expect(result.data).toHaveLength(1);
    expect(result.meta.total).toBe(1);
  });

  it('EX2: getExhibition by numeric id — found', async () => {
    mockPrisma.exhibitions.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getExhibition('1');
    expect(result.id).toEqual(BigInt(1));
  });

  it('EX3: getExhibition by slug — found', async () => {
    mockPrisma.exhibitions.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getExhibition('test-exhibition');
    expect(result.slug).toBe('test-exhibition');
  });

  it('EX4: getExhibition — not found: throws NotFoundException', async () => {
    mockPrisma.exhibitions.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getExhibition('missing')).rejects.toThrow(NotFoundException);
  });

  it('EX5: createExhibition — delegates to prisma.create', async () => {
    mockPrisma.exhibitions.create.mockResolvedValueOnce(STUB);
    const result = await svc.createExhibition({
      title: 'Test Exhibition', slug: 'test-exhibition', venue_id: 1,
      starts_at: '2025-01-01', ends_at: '2025-03-31',
    });
    expect(mockPrisma.exhibitions.create).toHaveBeenCalled();
    expect(result.title).toBe('Test Exhibition');
  });

  it('EX6: updateExhibition — found: delegates to prisma.update', async () => {
    mockPrisma.exhibitions.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.exhibitions.update.mockResolvedValueOnce({ ...STUB, title: 'Updated' });
    const result = await svc.updateExhibition('test-exhibition', { title: 'Updated' });
    expect(result.title).toBe('Updated');
  });

  it('EX7: tagSdgs — replaces SDG tags', async () => {
    mockPrisma.exhibitions.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.artmetro_exhibition_sdg.deleteMany.mockResolvedValueOnce({});
    mockPrisma.artmetro_exhibition_sdg.createMany.mockResolvedValueOnce({ count: 2 });
    const result = await svc.tagSdgs('test-exhibition', [4, 13]);
    expect(result.sdg_numbers).toEqual([4, 13]);
  });
});
