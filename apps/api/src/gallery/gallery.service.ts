import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

export interface GalleriesQuery {
  status?: string;
  type?: string;
  page?: number;
  perPage?: number;
}

@Injectable()
export class GalleryService {
  constructor(private readonly prisma: PrismaService) {}

  async listGalleries(query: GalleriesQuery = {}) {
    const { status = 'active', type, page = 1, perPage = 20 } = query;
    const skip = (page - 1) * perPage;

    const where: Record<string, unknown> = {};
    if (status) where['status'] = status;
    if (type) where['type'] = type;

    const [data, total] = await this.prisma.$transaction([
      this.prisma.galleries.findMany({
        where,
        select: {
          id: true, name: true, slug: true, type: true, status: true,
          website: true, contact_email: true, created_at: true,
        },
        orderBy: [{ name: 'asc' }],
        skip,
        take: perPage,
      }),
      this.prisma.galleries.count({ where }),
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

  async getGallery(idOrSlug: string) {
    const byId = /^\d+$/.test(idOrSlug)
      ? await this.prisma.galleries.findUnique({ where: { id: BigInt(idOrSlug) } })
      : null;

    const gallery = byId ?? await this.prisma.galleries.findUnique({ where: { slug: idOrSlug } });

    if (!gallery) throw new NotFoundException(`Gallery "${idOrSlug}" not found`);
    return gallery;
  }

  async listGalleryLots(idOrSlug: string) {
    const gallery = await this.getGallery(idOrSlug);

    return this.prisma.art_lots.findMany({
      where: { gallery_id: gallery.id },
      select: {
        id: true, sale_mode: true, status: true,
        starting_bid_cents: true, buy_now_price_cents: true,
        currency: true, estimate_low_cents: true, estimate_high_cents: true,
        published_at: true, closed_at: true,
        artworks: { select: { id: true, title: true, slug: true, medium: true } },
      },
      orderBy: [{ created_at: 'desc' }],
    });
  }
}
