/**
 * Phase 0.3V — ConsignmentService unit tests
 *
 * CON1  listConsignments — admin sees all
 * CON2  listConsignments — non-admin scoped to consignor_id
 * CON3  getConsignment — found, owner: returns it
 * CON4  getConsignment — not found: throws NotFoundException
 * CON5  getConsignment — found, different user: throws ForbiddenException
 * CON6  createConsignment — delegates to prisma.create
 * CON7  transition — found: updates status
 * CON8  createLot — owner: creates art_lots row with BigInt fields
 * CON9  createLot — not found: throws NotFoundException (via getConsignment)
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException, ForbiddenException } from '@nestjs/common';
import { ConsignmentService } from './consignment.service';
import { PrismaService } from '../prisma.service';

const STUB = {
  id: BigInt(1), artwork_id: BigInt(5), owner_id: BigInt(10), consignor_id: BigInt(10),
  gallery_id: null, commission_bps: 0, status: 'draft',
  starts_at: null, ends_at: null, created_at: new Date(),
  artworks: { id: BigInt(5), title: 'Test', slug: 'test', medium: 'painting' },
  galleries: null,
};

const fn = () => jest.fn() as any;
const mockPrisma = {
  consignments: { findMany: fn(), findFirst: fn(), count: fn(), create: fn(), update: fn() },
  art_lots: { create: fn() },
  $transaction: fn(),
};

describe('Phase 0.3V — ConsignmentService', () => {
  let svc: ConsignmentService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [ConsignmentService, { provide: PrismaService, useValue: mockPrisma }],
    }).compile();
    svc = module.get(ConsignmentService);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  it('CON1: listConsignments — admin sees all', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[STUB], 1]);
    const result = await svc.listConsignments(1, 'admin');
    expect(result.data).toHaveLength(1);
  });

  it('CON2: listConsignments — buyer scoped to own', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.listConsignments(99, 'buyer');
    expect(result.meta.total).toBe(0);
  });

  it('CON3: getConsignment — owner: returns it', async () => {
    mockPrisma.consignments.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getConsignment(1, 10, 'artist');
    expect(result.id).toEqual(BigInt(1));
  });

  it('CON4: getConsignment — not found: throws NotFoundException', async () => {
    mockPrisma.consignments.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getConsignment(999, 1, 'artist')).rejects.toThrow(NotFoundException);
  });

  it('CON5: getConsignment — different user: throws ForbiddenException', async () => {
    mockPrisma.consignments.findFirst.mockResolvedValueOnce(STUB); // consignor_id=10
    await expect(svc.getConsignment(1, 99, 'buyer')).rejects.toThrow(ForbiddenException);
  });

  it('CON6: createConsignment — delegates to prisma.create', async () => {
    mockPrisma.consignments.create.mockResolvedValueOnce(STUB);
    const result = await svc.createConsignment(10, { artwork_id: 5 });
    expect(mockPrisma.consignments.create).toHaveBeenCalled();
    expect(result.status).toBe('draft');
  });

  it('CON7: transition — found: updates status', async () => {
    mockPrisma.consignments.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.consignments.update.mockResolvedValueOnce({ ...STUB, status: 'active' });
    const result = await svc.transition(1, 10, 'artist', 'active');
    expect(result.status).toBe('active');
  });

  it('CON8: createLot — owner: creates art_lots row with correct fields', async () => {
    mockPrisma.consignments.findFirst.mockResolvedValueOnce(STUB); // via getConsignment
    mockPrisma.art_lots.create.mockResolvedValueOnce({ id: BigInt(1), status: 'draft', sale_mode: 'auction', created_at: new Date() });
    const result = await svc.createLot(1, 10, 'artist', { sale_mode: 'auction', starting_bid_cents: 5000 });
    expect(mockPrisma.art_lots.create).toHaveBeenCalledTimes(1);
    const arg = mockPrisma.art_lots.create.mock.calls[0][0] as any;
    expect(arg.data.consignment_id).toEqual(BigInt(1));
    expect(arg.data.consignor_id).toEqual(BigInt(10));
    expect(arg.data.starting_bid_cents).toEqual(BigInt(5000));
    expect(arg.data.status).toBe('draft');
    expect(result.status).toBe('draft');
  });

  it('CON9: createLot — not found: throws NotFoundException', async () => {
    mockPrisma.consignments.findFirst.mockResolvedValueOnce(null);
    await expect(svc.createLot(999, 10, 'artist', {})).rejects.toThrow(NotFoundException);
    expect(mockPrisma.art_lots.create).not.toHaveBeenCalled();
  });
});
