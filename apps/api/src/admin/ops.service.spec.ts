/**
 * Phase 0.3V — OpsService unit tests
 *
 * OP1  summary — counts pending/failed events and open reserves via $transaction
 * OP2  pendingDomainEvents — queries domain_events where status=pending
 * OP3  failedOutbox — queries domain_events where status=failed
 * OP4  openReserves — queries reserves where status != released
 * OP5  retryOutboxItem — found: sets status pending, returns { retried: true }
 * OP6  retryOutboxItem — not found: returns { retried: false }
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { OpsService } from './ops.service';
import { PrismaService } from '../prisma.service';

const fn = () => jest.fn() as any;
const mockPrisma = {
  domain_events: { findMany: fn(), findFirst: fn(), count: fn(), update: fn() },
  reserves: { findMany: fn(), count: fn() },
  $transaction: fn(),
};

describe('Phase 0.3V — OpsService', () => {
  let svc: OpsService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [OpsService, { provide: PrismaService, useValue: mockPrisma }],
    }).compile();
    svc = module.get(OpsService);
  });

  beforeEach(() => { jest.clearAllMocks(); });

  it('OP1: summary — counts via $transaction', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([3, 1, 2]);
    const result = await svc.summary();
    expect(mockPrisma.$transaction).toHaveBeenCalledTimes(1);
    expect(result.data.pending_domain_events).toBe(3);
    expect(result.data.failed_outbox).toBe(1);
    expect(result.data.open_reserves).toBe(2);
  });

  it('OP2: pendingDomainEvents — queries domain_events status=pending', async () => {
    const row = { id: BigInt(1), event_type: 'ArtworkPublished', aggregate_type: 'artworks', aggregate_id: BigInt(1), status: 'pending', created_at: new Date(), next_attempt_at: null };
    mockPrisma.domain_events.findMany.mockResolvedValueOnce([row]);
    const result = await svc.pendingDomainEvents();
    expect(mockPrisma.domain_events.findMany).toHaveBeenCalledTimes(1);
    const arg = mockPrisma.domain_events.findMany.mock.calls[0][0] as any;
    expect(arg.where.status).toBe('pending');
    expect(result.data).toHaveLength(1);
    expect(result.meta.count).toBe(1);
  });

  it('OP3: failedOutbox — queries domain_events status=failed', async () => {
    mockPrisma.domain_events.findMany.mockResolvedValueOnce([]);
    const result = await svc.failedOutbox();
    const arg = mockPrisma.domain_events.findMany.mock.calls[0][0] as any;
    expect(arg.where.status).toBe('failed');
    expect(result.data).toHaveLength(0);
  });

  it('OP4: openReserves — queries reserves where status != released', async () => {
    const row = { id: BigInt(1), auction_item_id: BigInt(5), reserve_price_cents: BigInt(10000), highest_bid_cents: BigInt(8000), status: 'not_reached', created_at: new Date() };
    mockPrisma.reserves.findMany.mockResolvedValueOnce([row]);
    const result = await svc.openReserves();
    const arg = mockPrisma.reserves.findMany.mock.calls[0][0] as any;
    expect(arg.where.status.not).toBe('released');
    expect(result.data).toHaveLength(1);
  });

  it('OP5: retryOutboxItem — found: resets status, returns retried true', async () => {
    const row = { id: BigInt(1), status: 'failed' };
    mockPrisma.domain_events.findFirst.mockResolvedValueOnce(row);
    mockPrisma.domain_events.update.mockResolvedValueOnce({ id: BigInt(1) });
    const result = await svc.retryOutboxItem(1);
    expect(mockPrisma.domain_events.update).toHaveBeenCalledTimes(1);
    const arg = mockPrisma.domain_events.update.mock.calls[0][0] as any;
    expect(arg.data.status).toBe('pending');
    expect(result.retried).toBe(true);
  });

  it('OP6: retryOutboxItem — not found: returns retried false', async () => {
    mockPrisma.domain_events.findFirst.mockResolvedValueOnce(null);
    const result = await svc.retryOutboxItem(999);
    expect(mockPrisma.domain_events.update).not.toHaveBeenCalled();
    expect(result.retried).toBe(false);
  });
});
