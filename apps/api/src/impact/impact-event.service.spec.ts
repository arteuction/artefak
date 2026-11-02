/**
 * Phase 0.3U â€” ImpactEventService unit tests
 *
 * IE1  listEvents default pagination
 * IE2  listEvents custom pagination
 * IE3  getEvent â€” found
 * IE4  getEvent â€” not found: throws NotFoundException
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { ImpactEventService } from './impact-event.service';
import { PrismaService } from '../prisma.service';

const STUB = { id: BigInt(1), sdg_number: 13, metric: 'CO2_tons', magnitude: 10, currency: null, type: 'direct', created_at: new Date() };
const fn = () => jest.fn() as any;
const mockPrisma = { impact_events: { findMany: fn(), findFirst: fn(), count: fn() }, $transaction: fn() };

describe('Phase 0.3U â€” ImpactEventService', () => {
  let svc: ImpactEventService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [ImpactEventService, { provide: PrismaService, useValue: mockPrisma }],
    }).compile();
    svc = module.get(ImpactEventService);
  });

  beforeEach(() => { jest.clearAllMocks(); });

  it('IE1: listEvents default pagination', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[STUB], 1]);
    const result = await svc.listEvents();
    expect(result.data).toHaveLength(1);
    expect(result.meta.current_page).toBe(1);
    expect(result.meta.per_page).toBe(20);
  });

  it('IE2: listEvents custom pagination', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 5]);
    const result = await svc.listEvents(2, 3);
    expect(result.meta.current_page).toBe(2);
    expect(result.meta.per_page).toBe(3);
    expect(result.meta.last_page).toBe(2);
  });

  it('IE3: getEvent â€” found', async () => {
    mockPrisma.impact_events.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getEvent(1);
    expect(result.sdg_number).toBe(13);
  });

  it('IE4: getEvent â€” not found: throws NotFoundException', async () => {
    mockPrisma.impact_events.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getEvent(999)).rejects.toThrow(NotFoundException);
  });
});
