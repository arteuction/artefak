import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

export interface ArtworksQuery {
  status?: string;
  medium?: string;
  page?: number;
  perPage?: number;
}

@Injectable()
export class ArtworkService {
  constructor(private readonly prisma: PrismaService) {}

  async listArtworks(query: ArtworksQuery = {}) {
    const { status, medium, page = 1, perPage = 20 } = query;
    const skip = (page - 1) * perPage;

    const where: Record<string, unknown> = { deleted_at: null };
    if (status) where['status'] = status;
    if (medium) where['medium'] = medium;

    const [data, total] = await this.prisma.$transaction([
      this.prisma.artworks.findMany({
        where,
        select: {
          id: true, title: true, slug: true, medium: true, status: true,
          primary_image_key: true, year_created: true, created_at: true,
        },
        orderBy: [{ created_at: 'desc' }],
        skip,
        take: perPage,
      }),
      this.prisma.artworks.count({ where }),
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

  async getArtwork(idOrSlug: string) {
    const byId = /^\d+$/.test(idOrSlug)
      ? await this.prisma.artworks.findFirst({ where: { id: BigInt(idOrSlug), deleted_at: null } })
      : null;

    const artwork = byId ?? await this.prisma.artworks.findFirst({
      where: { slug: idOrSlug, deleted_at: null },
    });

    if (!artwork) throw new NotFoundException(`Artwork "${idOrSlug}" not found`);
    return artwork;
  }

  async listArtLots(artworkIdOrSlug: string) {
    const artwork = await this.getArtwork(artworkIdOrSlug);

    return this.prisma.art_lots.findMany({
      where: { artwork_id: artwork.id },
      select: {
        id: true, sale_mode: true, status: true,
        starting_bid_cents: true, buy_now_price_cents: true,
        reserve_price_cents: true, currency: true,
        estimate_low_cents: true, estimate_high_cents: true,
        published_at: true, closed_at: true,
      },
      orderBy: [{ created_at: 'desc' }],
    });
  }
}
