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

  async getUserConsignments(userId: number, page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const where = { consignor_id: BigInt(userId) };

    const [data, total] = await this.prisma.$transaction([
      this.prisma.consignments.findMany({
        where,
        select: {
          id: true, commission_bps: true, status: true,
          starts_at: true, ends_at: true, created_at: true,
          artworks: { select: { id: true, title: true, slug: true, medium: true } },
          galleries: { select: { id: true, name: true, slug: true } },
        },
        orderBy: [{ created_at: 'desc' }],
        skip,
        take: perPage,
      }),
      this.prisma.consignments.count({ where }),
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

  async getWonItems(userId: number, page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const where = { winner_user_id: BigInt(userId), status: 'sold' as const };

    const [data, total] = await this.prisma.$transaction([
      this.prisma.auctionItem.findMany({
        where,
        select: {
          id: true, lot_number: true, status: true, fulfillment_status: true,
          payment_deadline: true, created_at: true,
          art_lots: { select: { id: true, status: true, sale_mode: true } },
          auction: { select: { id: true, title: true, slug: true } },
        },
        orderBy: [{ created_at: 'desc' }],
        skip,
        take: perPage,
      }),
      this.prisma.auctionItem.count({ where }),
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

  async getArtistProfile(userId: number) {
    const profile = await this.prisma.artist_profiles.findFirst({
      where: { user_id: BigInt(userId), deleted_at: null },
      select: {
        id: true, display_name: true, slug: true, bio: true,
        website: true, instagram_handle: true, status: true,
        terms_accepted_at: true, created_at: true,
      },
    });
    if (!profile) throw new NotFoundException('Artist profile not found');
    return profile;
  }

  async createArtistProfile(userId: number, dto: {
    display_name: string; slug: string; bio?: string;
    website?: string; instagram_handle?: string;
    terms_accepted_at?: string;
  }) {
    return this.prisma.artist_profiles.create({
      data: {
        user_id: BigInt(userId),
        display_name: dto.display_name,
        slug: dto.slug,
        bio: dto.bio ?? null,
        website: dto.website ?? null,
        instagram_handle: dto.instagram_handle ?? null,
        terms_accepted_at: dto.terms_accepted_at ? new Date(dto.terms_accepted_at) : null,
        status: 'pending',
      },
      select: {
        id: true, display_name: true, slug: true, bio: true,
        website: true, instagram_handle: true, status: true,
        terms_accepted_at: true, created_at: true,
      },
    });
  }

  async updateArtistProfile(userId: number, dto: {
    display_name?: string; bio?: string;
    website?: string; instagram_handle?: string;
  }) {
    const existing = await this.prisma.artist_profiles.findFirst({
      where: { user_id: BigInt(userId), deleted_at: null },
    });
    if (!existing) throw new NotFoundException('Artist profile not found');

    return this.prisma.artist_profiles.update({
      where: { id: existing.id },
      data: {
        ...(dto.display_name !== undefined ? { display_name: dto.display_name } : {}),
        ...(dto.bio !== undefined ? { bio: dto.bio } : {}),
        ...(dto.website !== undefined ? { website: dto.website } : {}),
        ...(dto.instagram_handle !== undefined ? { instagram_handle: dto.instagram_handle } : {}),
      },
      select: {
        id: true, display_name: true, slug: true, bio: true,
        website: true, instagram_handle: true, status: true,
        terms_accepted_at: true, created_at: true,
      },
    });
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
