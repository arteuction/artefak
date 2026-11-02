import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

export interface CollectionsQuery {
  page?: number;
  perPage?: number;
}

@Injectable()
export class CollectionService {
  constructor(private readonly prisma: PrismaService) {}

  async listPublicCollections(query: CollectionsQuery = {}) {
    const { page = 1, perPage = 20 } = query;
    const skip = (page - 1) * perPage;

    const where = { visibility: 'public' };

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

  async getCollection(idOrSlug: string) {
    const byId = /^\d+$/.test(idOrSlug)
      ? await this.prisma.collections.findFirst({ where: { id: BigInt(idOrSlug), visibility: 'public' } })
      : null;

    const collection = byId ?? await this.prisma.collections.findFirst({
      where: { slug: idOrSlug, visibility: 'public' },
    });

    if (!collection) throw new NotFoundException(`Collection "${idOrSlug}" not found`);
    return collection;
  }

  async getCollectionArtworks(idOrSlug: string) {
    const collection = await this.getCollection(idOrSlug);

    const items = await this.prisma.collection_artworks.findMany({
      where: { collection_id: collection.id },
      select: {
        artworks: {
          select: {
            id: true, title: true, slug: true, medium: true, status: true,
            primary_image_key: true, year_created: true,
          },
        },
        position: true,
      },
      orderBy: [{ position: 'asc' }],
    });

    return items.map((item) => ({ ...item.artworks, position: item.position }));
  }
}
