/**
 * Phase 0.3U â€” ImpactEventService unit tests
 *
 * IE1  listEvents default pagination
 * IE2  listEvents custom pagination
 * IE3  getEvent â€” found
 * IE4  getEvent — not found: throws NotFoundException
 * IE5  getEventEvidence — returns evidence for event
 * IE6  createEventEvidence — writes to impact_evidence (THREE-LEDGER: never ledger_entries)
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { ImpactEventService } from './impact-event.service';
import { PrismaService } from '../prisma.service';

const STUB = { id: BigInt(1), sdg_number: 13, metric: 'CO2_tons', magnitude: 10, currency: null, type: 'direct', created_at: new Date() };
const fn = () => jest.fn() as any;
const mockPrisma = {
  impact_events:  { findMany: fn(), findFirst: fn(), count: fn() },
  impact_evidence:{ findMany: fn(), create: fn() },
  $transaction: fn(),
};

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

  it('IE4: getEvent — not found: throws NotFoundException', async () => {
    mockPrisma.impact_events.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getEvent(999)).rejects.toThrow(NotFoundException);
  });

  it('IE5: getEventEvidence — returns evidence list', async () => {
    mockPrisma.impact_events.findFirst.mockResolvedValueOnce(STUB);
    const evidence = [{ id: BigInt(1), type: 'photo', url: 'https://example.com/img.jpg', description: null, created_at: new Date() }];
    mockPrisma.impact_evidence.findMany.mockResolvedValueOnce(evidence);
    const result = await svc.getEventEvidence(1);
    expect(result).toHaveLength(1);
    expect(result[0].type).toBe('photo');
  });

  it('IE6: createEventEvidence — writes to impact_evidence (THREE-LEDGER: never ledger_entries)', async () => {
    mockPrisma.impact_events.findFirst.mockResolvedValueOnce(STUB);
    const created = { id: BigInt(2), type: 'document', url: 'https://example.com/doc.pdf', description: 'Proof', created_at: new Date() };
    mockPrisma.impact_evidence.create.mockResolvedValueOnce(created);
    const result = await svc.createEventEvidence(1, 42, { type: 'document', url: 'https://example.com/doc.pdf', description: 'Proof' });
    expect(mockPrisma.impact_evidence.create).toHaveBeenCalledTimes(1);
    const arg = mockPrisma.impact_evidence.create.mock.calls[0][0] as any;
    expect(arg.data.uploaded_by).toEqual(BigInt(42));
    expect(arg.data.impact_event_id).toEqual(STUB.id);
    expect(result.type).toBe('document');
  });
});
