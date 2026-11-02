import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

export interface ArtistsQuery {
  page?: number;
  perPage?: number;
}

@Injectable()
export class ArtistService {
  constructor(private readonly prisma: PrismaService) {}

  async listArtists(query: ArtistsQuery = {}) {
    const { page = 1, perPage = 20 } = query;
    const skip = (page - 1) * perPage;

    const where = { status: 'approved' as const };

    const [data, total] = await this.prisma.$transaction([
      this.prisma.artist_profiles.findMany({
        where,
        select: {
          id: true, display_name: true, slug: true, bio: true,
          website: true, instagram_handle: true, status: true, created_at: true,
        },
        orderBy: [{ display_name: 'asc' }],
        skip,
        take: perPage,
      }),
      this.prisma.artist_profiles.count({ where }),
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

  async getArtist(idOrSlug: string) {
    const byId = /^\d+$/.test(idOrSlug)
      ? await this.prisma.artist_profiles.findUnique({ where: { id: BigInt(idOrSlug) } })
      : null;

    const profile = byId ?? await this.prisma.artist_profiles.findUnique({ where: { slug: idOrSlug } });

    if (!profile || profile.status !== 'approved') {
      throw new NotFoundException(`Artist "${idOrSlug}" not found`);
    }
    return profile;
  }

  async getArtistArtworks(idOrSlug: string, page = 1, perPage = 20) {
    const artist = await this.getArtist(idOrSlug);
    const skip = (page - 1) * perPage;

    const where = { user_id: artist.user_id, deleted_at: null };

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
}
