import { Injectable } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

@Injectable()
export class SearchService {
  constructor(private readonly prisma: PrismaService) {}

  async searchArtworks(query: string, page = 1, perPage = 20) {
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
}
