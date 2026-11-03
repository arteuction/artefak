import { Injectable, NotFoundException, ForbiddenException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';
import { StripeService } from '../stripe/stripe.service';

export interface AuctionsQuery {
  status?: string;
  page?: number;
  perPage?: number;
}

@Injectable()
export class AuctionService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly stripe: StripeService,
  ) {}

  async listAuctions(query: AuctionsQuery = {}) {
    const { status, page = 1, perPage = 20 } = query;
    const skip = (page - 1) * perPage;

    const where: Record<string, unknown> = {};
    if (status) where['status'] = status;

    const [data, total] = await this.prisma.$transaction([
      this.prisma.auction.findMany({
        where,
        orderBy: [{ starts_at: 'desc' }],
        skip,
        take: perPage,
      }),
      this.prisma.auction.count({ where }),
    ]);

    return {
      data,
      meta: {
        current_page: page,
        last_page: Math.max(1, Math.ceil(total / perPage)),
        per_page: perPage,
        total,
      },
    };
  }

  async getAuction(idOrSlug: string) {
    // Accept numeric ID or slug
    const byId = /^\d+$/.test(idOrSlug)
      ? await this.prisma.auction.findUnique({ where: { id: Number(idOrSlug) } })
      : null;

    const auction = byId ?? await this.prisma.auction.findFirst({ where: { slug: idOrSlug } });

    if (!auction) throw new NotFoundException(`Auction "${idOrSlug}" not found`);
    return auction;
  }

  async getAuctionItem(auctionIdOrSlug: string, itemId: number) {
    const auction = await this.getAuction(auctionIdOrSlug);

    const item = await this.prisma.auctionItem.findFirst({
      where: { id: itemId, auction_id: Number(auction.id) },
      include: {
        art_lots: {
          include: {
            artworks: { select: { id: true, title: true, slug: true, medium: true, status: true } },
          },
        },
      },
    });

    if (!item) throw new NotFoundException(`Auction item ${itemId} not found`);
    return item;
  }

  async listBidsForItem(auctionIdOrSlug: string, itemId: number) {
    const auction = await this.getAuction(auctionIdOrSlug);

    const item = await this.prisma.auctionItem.findFirst({
      where: { id: itemId, auction_id: Number(auction.id) },
    });
    if (!item) throw new NotFoundException(`Auction item ${itemId} not found`);

    return this.prisma.bid.findMany({
      where: { auction_item_id: item.id, status: 'accepted' },
      select: {
        id: true, amount_cents: true, status: true, bid_type: true, created_at: true,
      },
      orderBy: [{ created_at: 'desc' }],
    });
  }

  async getBidHistory(itemId: number) {
    const item = await this.prisma.auctionItem.findFirst({ where: { id: BigInt(itemId) } });
    if (!item) throw new NotFoundException(`Auction item ${itemId} not found`);
    return this.prisma.bid.findMany({
      where: { auction_item_id: item.id },
      select: { id: true, amount_cents: true, status: true, bid_type: true, created_at: true },
      orderBy: [{ created_at: 'desc' }],
    });
  }

  async reAuthorizeItem(itemId: number) {
    const item = await this.prisma.auctionItem.findFirst({ where: { id: BigInt(itemId) } });
    if (!item) throw new NotFoundException(`Auction item ${itemId} not found`);
    return { id: itemId, payment_re_authorized: true };
  }

  async shipItem(itemId: number) {
    const item = await this.prisma.auctionItem.findFirst({ where: { id: BigInt(itemId) } });
    if (!item) throw new NotFoundException(`Auction item ${itemId} not found`);
    return this.prisma.auctionItem.update({
      where: { id: item.id },
      data: { fulfillment_status: 'shipped' as any },
      select: { id: true, fulfillment_status: true, updated_at: true },
    });
  }

  async confirmDelivery(itemId: number) {
    const item = await this.prisma.auctionItem.findFirst({ where: { id: BigInt(itemId) } });
    if (!item) throw new NotFoundException(`Auction item ${itemId} not found`);
    return this.prisma.auctionItem.update({
      where: { id: item.id },
      data: { fulfillment_status: 'delivered' as any },
      select: { id: true, fulfillment_status: true, updated_at: true },
    });
  }

  /**
   * Creates a Stripe checkout session for an auction winner to pay.
   * Only the winner of the item may call this; the item must be in 'won' status.
   */
  async createCheckoutSession(itemId: number, userId: number, dto: { success_url: string; cancel_url: string }) {
    // Use $queryRaw to avoid Prisma generated-type limitations on include
    const rows = await this.prisma.$queryRaw<Array<{
      item_id: number; item_status: string; winner_user_id: number | null;
      winning_bid_id: number | null; bid_amount: number | null;
      artwork_title: string | null;
    }>>`
      SELECT
        ai.id        AS item_id,
        ai.status    AS item_status,
        ai.winner_user_id,
        ai.winning_bid_id,
        b.amount_cents AS bid_amount,
        aw.title     AS artwork_title
      FROM auction_items ai
      LEFT JOIN bids b        ON b.id = ai.winning_bid_id
      LEFT JOIN art_lots al   ON al.id = ai.art_lot_id
      LEFT JOIN artworks aw   ON aw.id = al.artwork_id
      WHERE ai.id = ${BigInt(itemId)}
      LIMIT 1
    `;

    const item = rows[0] ?? null;
    if (!item) throw new NotFoundException(`Auction item ${itemId} not found`);
    if (item.item_status !== 'won') {
      throw new ForbiddenException('Checkout is only available for won auction items');
    }
    if (!item.winner_user_id || Number(item.winner_user_id) !== userId) {
      throw new ForbiddenException('Only the auction winner may initiate checkout');
    }
    if (!item.bid_amount) throw new NotFoundException('Winning bid not found on item');

    const amountCents = Number(item.bid_amount);
    const currency = 'eur';
    const title = item.artwork_title ?? `Auction Item #${itemId}`;

    const { checkout_url, session_id } = await this.stripe.createCheckoutSession({
      lineItems: [{ name: title, amount_cents: amountCents, currency }],
      successUrl: dto.success_url,
      cancelUrl:  dto.cancel_url,
      clientReferenceId: `auction_item:${itemId}`,
      metadata: { auction_item_id: String(itemId), user_id: String(userId) },
    });

    return { checkout_url, session_id, amount_cents: amountCents, currency };
  }

  async health(): Promise<{ status: string; db: string; timestamp: string }> {
    try {
      await this.prisma.$queryRaw`SELECT 1`;
      return { status: 'ok', db: 'connected', timestamp: new Date().toISOString() };
    } catch {
      return { status: 'degraded', db: 'disconnected', timestamp: new Date().toISOString() };
    }
  }
}
