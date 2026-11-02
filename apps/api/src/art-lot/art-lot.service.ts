import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

@Injectable()
export class ArtLotService {
  constructor(private readonly prisma: PrismaService) {}

  async listArtLots(query: { status?: string; page?: number; perPage?: number } = {}) {
    const { status, page = 1, perPage = 20 } = query;
    const skip = (page - 1) * perPage;
    const where: Record<string, unknown> = {};
    if (status) where['status'] = status;

    const [data, total] = await this.prisma.$transaction([
      this.prisma.art_lots.findMany({
        where,
        select: {
          id: true, sale_mode: true, status: true,
          starting_bid_cents: true, buy_now_price_cents: true,
          currency: true, estimate_low_cents: true, estimate_high_cents: true,
          published_at: true, closed_at: true, created_at: true,
        },
        orderBy: [{ created_at: 'desc' }],
        skip,
        take: perPage,
      }),
      this.prisma.art_lots.count({ where }),
    ]);

    return {
      data,
      meta: {
        current_page: page,
        last_page: Math.max(1, Math.ceil(Number(total) / perPage)),
        per_page: perPage,
        total: Number(total),
      },
    };
  }

  async getArtLot(idOrSlug: string) {
    const lot = /^\d+$/.test(idOrSlug)
      ? await this.prisma.art_lots.findFirst({ where: { id: BigInt(idOrSlug) } })
      : await this.prisma.art_lots.findFirst({ where: { slug: idOrSlug } as never });

    if (!lot) throw new NotFoundException(`ArtLot "${idOrSlug}" not found`);
    return lot;
  }

  async listBids(idOrSlug: string) {
    const lot = await this.getArtLot(idOrSlug);
    return this.prisma.bid.findMany({
      where: { auction_item_id: lot.id, status: 'accepted' },
      select: { id: true, amount_cents: true, status: true, bid_type: true, created_at: true },
      orderBy: [{ created_at: 'desc' }],
    });
  }

  async getProvenance(idOrSlug: string) {
    // Provenance (ownership history) — stub returns empty array until provenance domain is built
    await this.getArtLot(idOrSlug);
    return [];
  }
}
