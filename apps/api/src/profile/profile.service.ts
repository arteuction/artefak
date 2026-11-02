import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

@Injectable()
export class ProfileService {
  constructor(private readonly prisma: PrismaService) {}

  async getWatchlist(userId: number) {
    return this.prisma.watchlist.findMany({
      where: { user_id: BigInt(userId) },
      select: {
        id: true, subject_type: true, subject_id: true, created_at: true,
      },
      orderBy: [{ created_at: 'desc' }],
    });
  }

  async addToWatchlist(userId: number, subjectType: string, subjectId: number) {
    return this.prisma.watchlist.upsert({
      where: {
        user_id_subject_type_subject_id: {
          user_id: BigInt(userId),
          subject_type: subjectType,
          subject_id: BigInt(subjectId),
        },
      },
      create: {
        user_id: BigInt(userId),
        subject_type: subjectType,
        subject_id: BigInt(subjectId),
      },
      update: {},
      select: { id: true, subject_type: true, subject_id: true, created_at: true },
    });
  }

  async removeFromWatchlist(userId: number, watchlistId: number) {
    const entry = await this.prisma.watchlist.findFirst({
      where: { id: BigInt(watchlistId), user_id: BigInt(userId) },
    });
    if (!entry) throw new NotFoundException(`Watchlist entry ${watchlistId} not found`);
    await this.prisma.watchlist.delete({ where: { id: BigInt(watchlistId) } });
    return { deleted: true };
  }

  async getUserCollections(userId: number, page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const where = { owner_id: BigInt(userId), visibility: 'public' };

    const [data, total] = await this.prisma.$transaction([
      this.prisma.collections.findMany({
        where,
        select: {
          id: true, title: true, slug: true, description: true,
          cover_image_url: true, visibility: true, created_at: true,
        },
        orderBy: [{ created_at: 'desc' }],
        skip,
        take: perPage,
      }),
      this.prisma.collections.count({ where }),
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

  async getUserBids(userId: number, page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;

    const [data, total] = await this.prisma.$transaction([
      this.prisma.bid.findMany({
        where: { user_id: BigInt(userId) },
        select: {
          id: true, amount_cents: true, status: true, bid_type: true, created_at: true,
          auction_item: {
            select: {
              id: true, lot_number: true,
              auction: { select: { id: true, title: true, slug: true } },
            },
          },
        },
        orderBy: [{ created_at: 'desc' }],
        skip,
        take: perPage,
      }),
      this.prisma.bid.count({ where: { user_id: BigInt(userId) } }),
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
}
