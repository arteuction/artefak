import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';
import { StripeService } from '../stripe/stripe.service';

@Injectable()
export class AdminService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly stripe: StripeService,
  ) {}

  // ── Settlements ──────────────────────────────────────────────────────────────

  async listSettlements(page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const [data, total] = await this.prisma.$transaction([
      this.prisma.settlements.findMany({
        orderBy: [{ created_at: 'desc' }],
        select: {
          id: true, profile_key: true, gross_cents: true, currency: true,
          status: true, stripe_payment_intent_id: true, created_at: true,
        },
        skip,
        take: perPage,
      }),
      this.prisma.settlements.count(),
    ]);
    return { data, meta: { current_page: page, last_page: Math.max(1, Math.ceil(total / perPage)), per_page: perPage, total } };
  }

  async getSettlement(id: number) {
    const s = await this.prisma.settlements.findUnique({
      where: { id: BigInt(id) },
      include: {
        settlement_lines: {
          select: {
            id: true, recipient_type: true, amount_cents: true, currency: true,
            user_id: true, stripe_account_id: true,
          },
        },
      },
    });
    if (!s) throw new NotFoundException(`Settlement ${id} not found`);
    return s;
  }

  // ── Ledger ───────────────────────────────────────────────────────────────────

  async listLedger(page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const [data, total] = await this.prisma.$transaction([
      this.prisma.ledger_entries.findMany({
        orderBy: [{ created_at: 'desc' }],
        select: {
          id: true, type: true, amount_cents: true, currency: true,
          idempotency_key: true, note: true, created_at: true,
        },
        skip,
        take: perPage,
      }),
      this.prisma.ledger_entries.count(),
    ]);
    return { data, meta: { current_page: page, last_page: Math.max(1, Math.ceil(total / perPage)), per_page: perPage, total } };
  }

  // ── Refunds ──────────────────────────────────────────────────────────────────

  async listRefunds(page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const [data, total] = await this.prisma.$transaction([
      this.prisma.refunds.findMany({
        orderBy: [{ created_at: 'desc' }],
        select: {
          id: true, stripe_refund_id: true, amount_cents: true, currency: true,
          reason: true, refund_status: true, settlement_id: true, created_at: true,
        },
        skip,
        take: perPage,
      }),
      this.prisma.refunds.count(),
    ]);
    return { data, meta: { current_page: page, last_page: Math.max(1, Math.ceil(total / perPage)), per_page: perPage, total } };
  }

  // ── Users ────────────────────────────────────────────────────────────────────

  async listUsers(page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const [data, total] = await this.prisma.$transaction([
      this.prisma.user.findMany({
        orderBy: [{ created_at: 'desc' }],
        select: {
          id: true, email: true, name: true, role: true,
          email_verified_at: true, created_at: true,
        },
        skip,
        take: perPage,
      }),
      this.prisma.user.count(),
    ]);
    return { data, meta: { current_page: page, last_page: Math.max(1, Math.ceil(total / perPage)), per_page: perPage, total } };
  }

  async getUser(userId: number) {
    const u = await this.prisma.user.findUnique({
      where: { id: BigInt(userId) },
      select: {
        id: true, email: true, name: true, role: true,
        email_verified_at: true, created_at: true, updated_at: true,
      },
    });
    if (!u) throw new NotFoundException(`User ${userId} not found`);
    return u;
  }

  async updateUser(userId: number, dto: { role?: string; name?: string }) {
    const user = await this.prisma.user.findUnique({ where: { id: BigInt(userId) } });
    if (!user) throw new NotFoundException(`User ${userId} not found`);
    return this.prisma.user.update({
      where: { id: BigInt(userId) },
      data: {
        ...(dto.role && { role: dto.role as any }),
        ...(dto.name && { name: dto.name }),
      },
      select: { id: true, email: true, name: true, role: true, updated_at: true },
    });
  }

  // ── Transfer outbox ──────────────────────────────────────────────────────────

  async listTransferOutbox(page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const [data, total] = await this.prisma.$transaction([
      this.prisma.transfer_outbox.findMany({
        orderBy: [{ next_attempt_at: 'asc' }, { id: 'asc' }],
        skip,
        take: perPage,
      }),
      this.prisma.transfer_outbox.count(),
    ]);
    return { data, meta: { current_page: page, last_page: Math.max(1, Math.ceil(total / perPage)), per_page: perPage, total } };
  }

  async getTransferOutboxItem(id: number) {
    const item = await this.prisma.transfer_outbox.findUnique({ where: { id: BigInt(id) } });
    if (!item) throw new NotFoundException(`Transfer outbox item ${id} not found`);
    return item;
  }

  // ── Settlement lines / disburse ──────────────────────────────────────────────

  async listSettlementLines(page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const [data, total] = await this.prisma.$transaction([
      this.prisma.settlement_lines.findMany({
        orderBy: [{ id: 'desc' }],
        include: { settlements: { select: { id: true, status: true, currency: true } } },
        skip,
        take: perPage,
      }),
      this.prisma.settlement_lines.count(),
    ]);
    return { data, meta: { current_page: page, last_page: Math.max(1, Math.ceil(total / perPage)), per_page: perPage, total } };
  }

  async disburseSettlementLine(lineId: number) {
    const line = await this.prisma.settlement_lines.findUnique({
      where: { id: BigInt(lineId) },
      select: { id: true, amount_cents: true, currency: true, stripe_account_id: true, status: true },
    });
    if (!line) throw new NotFoundException(`Settlement line ${lineId} not found`);
    if ((line as any).status === 'paid') return { disbursed: false, reason: 'already_paid' };

    if (line.stripe_account_id && this.stripe.enabled) {
      await this.stripe.createTransfer({
        amount_cents: Number(line.amount_cents),
        currency: (line.currency ?? 'EUR').toLowerCase(),
        destination: line.stripe_account_id,
        metadata: { settlement_line_id: String(lineId) },
      });
    }

    await this.prisma.settlement_lines.update({
      where: { id: line.id },
      data: { status: 'paid' as any, updated_at: new Date() },
    });

    return { disbursed: true, line_id: lineId };
  }

  // ── Auctions admin ───────────────────────────────────────────────────────────

  async createAuction(dto: { title: string; slug: string; starts_at: string; ends_at: string; ruleset_id?: number }) {
    return this.prisma.auction.create({
      data: {
        title: dto.title,
        slug: dto.slug,
        starts_at: new Date(dto.starts_at),
        ends_at: new Date(dto.ends_at),
        status: 'draft' as any,
        ruleset_id: dto.ruleset_id ? BigInt(dto.ruleset_id) : null,
      },
      select: { id: true, title: true, slug: true, status: true, starts_at: true, ends_at: true },
    });
  }

  async updateAuction(auctionId: number, dto: { title?: string; starts_at?: string; ends_at?: string }) {
    const auction = await this.prisma.auction.findUnique({ where: { id: BigInt(auctionId) } });
    if (!auction) throw new NotFoundException(`Auction ${auctionId} not found`);
    return this.prisma.auction.update({
      where: { id: BigInt(auctionId) },
      data: {
        ...(dto.title && { title: dto.title }),
        ...(dto.starts_at && { starts_at: new Date(dto.starts_at) }),
        ...(dto.ends_at && { ends_at: new Date(dto.ends_at) }),
      },
      select: { id: true, title: true, status: true, starts_at: true, ends_at: true },
    });
  }

  async publishAuction(auctionId: number) {
    const auction = await this.prisma.auction.findUnique({ where: { id: BigInt(auctionId) } });
    if (!auction) throw new NotFoundException(`Auction ${auctionId} not found`);
    return this.prisma.auction.update({
      where: { id: BigInt(auctionId) },
      data: { status: 'published' as any },
      select: { id: true, status: true },
    });
  }

  async openAuction(auctionId: number) {
    const auction = await this.prisma.auction.findUnique({ where: { id: BigInt(auctionId) } });
    if (!auction) throw new NotFoundException(`Auction ${auctionId} not found`);
    return this.prisma.auction.update({
      where: { id: BigInt(auctionId) },
      data: { status: 'open' as any },
      select: { id: true, status: true },
    });
  }

  async closeAuction(auctionId: number) {
    const auction = await this.prisma.auction.findUnique({ where: { id: BigInt(auctionId) } });
    if (!auction) throw new NotFoundException(`Auction ${auctionId} not found`);
    return this.prisma.auction.update({
      where: { id: BigInt(auctionId) },
      data: { status: 'closed' as any },
      select: { id: true, status: true },
    });
  }

  async addAuctionItem(auctionId: number, dto: { art_lot_id: number; lot_number: number; bid_increment_cents?: number }) {
    const auction = await this.prisma.auction.findUnique({ where: { id: BigInt(auctionId) } });
    if (!auction) throw new NotFoundException(`Auction ${auctionId} not found`);

    const artLot = await this.prisma.art_lots.findUnique({ where: { id: BigInt(dto.art_lot_id) } });
    if (!artLot) throw new NotFoundException(`ArtLot ${dto.art_lot_id} not found`);

    return this.prisma.auctionItem.create({
      data: {
        auction_id:          BigInt(auctionId),
        art_lot_id:          BigInt(dto.art_lot_id),
        lot_number:          dto.lot_number,
        bid_increment_cents: dto.bid_increment_cents ? BigInt(dto.bid_increment_cents) : null,
        status:              'pending' as any,
        created_at:          new Date(),
        updated_at:          new Date(),
      },
      select: {
        id: true, auction_id: true, art_lot_id: true,
        lot_number: true, bid_increment_cents: true, status: true,
      },
    });
  }

  async removeAuctionItem(auctionId: number, itemId: number) {
    const item = await this.prisma.auctionItem.findFirst({
      where: { id: BigInt(itemId), auction_id: BigInt(auctionId) },
      select: { id: true, status: true },
    });
    if (!item) throw new NotFoundException(`Auction item ${itemId} not found in auction ${auctionId}`);
    if ((item.status as string) !== 'pending') {
      throw new NotFoundException(`Cannot remove item with status '${item.status as string}' — only pending items may be removed`);
    }
    await this.prisma.auctionItem.delete({ where: { id: BigInt(itemId) } });
    return { removed: true, item_id: itemId };
  }

  // ── Audit log ────────────────────────────────────────────────────────────────

  async listAuditLog(page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const [data, total] = await this.prisma.$transaction([
      this.prisma.admin_audit_log.findMany({
        orderBy: [{ created_at: 'desc' }],
        select: {
          id: true, actor_id: true, action: true, subject_type: true,
          subject_id: true, payload: true, created_at: true,
        },
        skip,
        take: perPage,
      }),
      this.prisma.admin_audit_log.count(),
    ]);
    return { data, meta: { current_page: page, last_page: Math.max(1, Math.ceil(total / perPage)), per_page: perPage, total } };
  }
}
