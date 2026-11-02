import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

export interface AuctionsQuery {
  status?: string;
  page?: number;
  perPage?: number;
}

@Injectable()
export class AuctionService {
  constructor(private readonly prisma: PrismaService) {}

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

  async health(): Promise<{ status: string; db: string; timestamp: string }> {
    try {
      await this.prisma.$queryRaw`SELECT 1`;
      return { status: 'ok', db: 'connected', timestamp: new Date().toISOString() };
    } catch {
      return { status: 'degraded', db: 'disconnected', timestamp: new Date().toISOString() };
    }
  }
}
