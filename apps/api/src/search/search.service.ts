import { Injectable } from '@nestjs/common';
import { PrismaService } from '../prisma.service';
import { MeilisearchService } from './meilisearch.service';

@Injectable()
export class SearchService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly meili: MeilisearchService,
  ) {}

  async searchArtworks(query: string, page = 1, perPage = 20) {
    if (this.meili.enabled) {
      const offset = (page - 1) * perPage;
      const result = await this.meili.searchArtworks(query, perPage, offset);
      return {
        data: result.hits,
        meta: {
          current_page: page,
          last_page: Math.max(1, Math.ceil(result.estimatedTotalHits / perPage)),
          per_page: perPage,
          total: result.estimatedTotalHits,
          query,
        },
      };
    }

    // SQL LIKE fallback when Meilisearch is not configured
    const skip = (page - 1) * perPage;
    const where = query
      ? {
          deleted_at: null,
          OR: [
            { title: { contains: query } },
            { description: { contains: query } },
          ],
        }
      : { deleted_at: null };

    const [data, total] = await this.prisma.$transaction([
      this.prisma.artworks.findMany({
        where,
        select: {
          id: true, title: true, slug: true, medium: true,
          year_created: true, status: true, created_at: true,
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
        query,
      },
    };
  }

  async getArtwork(idOrSlug: string) {
    const where = /^\d+$/.test(idOrSlug)
      ? { id: BigInt(idOrSlug), deleted_at: null }
      : { slug: idOrSlug, deleted_at: null };

    return this.prisma.artworks.findFirst({
      where,
      select: { id: true, title: true, slug: true, medium: true, year_created: true, status: true, created_at: true },
    });
  }
}
