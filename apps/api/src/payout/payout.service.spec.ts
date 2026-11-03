/**
 * Phase 0.5 — PayoutService (real settlement_lines queries)
 *
 * PS1  artistPayouts — returns lines + correct meta
 * PS2  artistPayouts — custom page/perPage
 * PS3  galleryPayouts — resolves legal_entity_id
 * PS4  galleryPayouts — throws NotFoundException when gallery not found
 * PS5  galleryPayouts — custom page/perPage
 * PS6  pendingTransfers — returns outbox rows + meta
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { PayoutService } from './payout.service';
import { PrismaService } from '../prisma.service';

const fn = () => jest.fn() as any;

const mockPrisma = {
  settlement_lines: { findMany: fn(), count: fn() },
  galleries:        { findUnique: fn() },
  transfer_outbox:  { findMany: fn(), count: fn() },
  $transaction:     fn(),
};

describe('Phase 0.5 — PayoutService', () => {
  let svc: PayoutService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        PayoutService,
        { provide: PrismaService, useValue: mockPrisma },
      ],
    }).compile();
    svc = module.get(PayoutService);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  it('PS1: artistPayouts — returns lines + correct meta', async () => {
    const lines = [{ id: BigInt(1), amount_cents: BigInt(4500) }];
    mockPrisma.$transaction.mockResolvedValueOnce([lines, 1]);

    const result = await svc.artistPayouts(42);

    expect(mockPrisma.$transaction).toHaveBeenCalled();
    expect(result.data).toEqual(lines);
    expect(result.meta.current_page).toBe(1);
    expect(result.meta.per_page).toBe(20);
    expect(result.meta.total).toBe(1);
    expect(result.meta.last_page).toBe(1);
  });

  it('PS2: artistPayouts — custom page/perPage', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 55]);

    const result = await svc.artistPayouts(42, 3, 10);

    expect(result.meta.current_page).toBe(3);
    expect(result.meta.per_page).toBe(10);
    expect(result.meta.last_page).toBe(6);
    expect(result.meta.total).toBe(55);
  });

  it('PS3: galleryPayouts — resolves legal_entity_id and returns lines', async () => {
    const gallery = { id: BigInt(7) };
    mockPrisma.galleries.findUnique.mockResolvedValueOnce(gallery);
    const lines = [{ id: BigInt(2), amount_cents: BigInt(9000) }];
    mockPrisma.$transaction.mockResolvedValueOnce([lines, 1]);

    const result = await svc.galleryPayouts(7);

    expect(mockPrisma.galleries.findUnique).toHaveBeenCalledWith(
      expect.objectContaining({ where: { id: BigInt(7) } })
    );
    expect(result.data).toEqual(lines);
    expect(result.meta.current_page).toBe(1);
  });

  it('PS4: galleryPayouts — throws NotFoundException for missing gallery', async () => {
    mockPrisma.galleries.findUnique.mockResolvedValueOnce(null);

    await expect(svc.galleryPayouts(999)).rejects.toBeInstanceOf(NotFoundException);
  });

  it('PS5: galleryPayouts — custom page/perPage', async () => {
    mockPrisma.galleries.findUnique.mockResolvedValueOnce({ id: BigInt(7) });
    mockPrisma.$transaction.mockResolvedValueOnce([[], 100]);

    const result = await svc.galleryPayouts(7, 2, 25);

    expect(result.meta.current_page).toBe(2);
    expect(result.meta.per_page).toBe(25);
    expect(result.meta.last_page).toBe(4);
  });

  it('PS6: pendingTransfers — returns outbox rows + meta', async () => {
    const rows = [{ id: BigInt(1), status: 'pending', amount_cents: BigInt(4500) }];
    mockPrisma.$transaction.mockResolvedValueOnce([rows, 3]);

    const result = await svc.pendingTransfers();

    expect(result.data).toEqual(rows);
    expect(result.meta.total).toBe(3);
    expect(result.meta.last_page).toBe(1);
  });
});
