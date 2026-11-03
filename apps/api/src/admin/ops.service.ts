import { Injectable } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

@Injectable()
export class OpsService {
  constructor(private readonly prisma: PrismaService) {}

  async pendingDomainEvents(limit = 50) {
    const rows = await this.prisma.domain_events.findMany({
      where: { status: 'pending' as any },
      orderBy: [{ created_at: 'asc' }],
      select: { id: true, event_type: true, aggregate_type: true, aggregate_id: true, status: true, created_at: true, next_attempt_at: true },
      take: limit,
    });
    return { data: rows, meta: { count: rows.length } };
  }

  async failedOutbox(limit = 50) {
    const rows = await this.prisma.domain_events.findMany({
      where: { status: 'failed' as any },
      orderBy: [{ updated_at: 'desc' }],
      select: { id: true, event_type: true, aggregate_type: true, aggregate_id: true, status: true, created_at: true, updated_at: true },
      take: limit,
    });
    return { data: rows, meta: { count: rows.length } };
  }

  async retryOutboxItem(id: number) {
    const row = await this.prisma.domain_events.findFirst({ where: { id: BigInt(id) } });
    if (!row) return { retried: false, reason: 'not_found' };
    await this.prisma.domain_events.update({
      where: { id: BigInt(id) },
      data: { status: 'pending' as any, next_attempt_at: new Date(), updated_at: new Date() },
    });
    return { retried: true };
  }

  async openReserves(limit = 50) {
    const rows = await this.prisma.reserves.findMany({
      where: { status: { not: 'released' as any } },
      orderBy: [{ created_at: 'asc' }],
      select: { id: true, auction_item_id: true, reserve_price_cents: true, highest_bid_cents: true, status: true, created_at: true },
      take: limit,
    });
    return { data: rows, meta: { count: rows.length } };
  }

  async summary() {
    const [pendingEvents, failedOutbox, openReserves] = await this.prisma.$transaction([
      this.prisma.domain_events.count({ where: { status: 'pending' as any } }),
      this.prisma.domain_events.count({ where: { status: 'failed' as any } }),
      this.prisma.reserves.count({ where: { status: { not: 'released' as any } } }),
    ]);
    return {
      data: {
        pending_domain_events: Number(pendingEvents),
        failed_outbox: Number(failedOutbox),
        open_reserves: Number(openReserves),
      },
    };
  }
}
