import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

@Injectable()
export class VenueService {
  constructor(private readonly prisma: PrismaService) {}

  async listVenues(page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const [data, total] = await this.prisma.$transaction([
      this.prisma.venues.findMany({
        select: {
          id: true, name: true, slug: true, address: true,
          latitude: true, longitude: true, is_active: true,
          created_at: true,
        },
        orderBy: [{ name: 'asc' }],
        skip,
        take: perPage,
      }),
      this.prisma.venues.count(),
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

  async getVenue(idOrSlug: string) {
    const venue = /^\d+$/.test(idOrSlug)
      ? await this.prisma.venues.findFirst({ where: { id: BigInt(idOrSlug) } })
      : await this.prisma.venues.findFirst({ where: { slug: idOrSlug } });

    if (!venue) throw new NotFoundException(`Venue "${idOrSlug}" not found`);
    return venue;
  }

  async createVenue(dto: Record<string, unknown>) {
    return this.prisma.venues.create({ data: dto as Parameters<typeof this.prisma.venues.create>[0]['data'] });
  }

  async updateVenue(idOrSlug: string, dto: Record<string, unknown>) {
    const venue = await this.getVenue(idOrSlug);
    return this.prisma.venues.update({ where: { id: venue.id }, data: dto as Parameters<typeof this.prisma.venues.update>[0]['data'] });
  }

  async getVenueMap() {
    return this.prisma.venues.findMany({
      select: { id: true, name: true, latitude: true, longitude: true, slug: true },
    });
  }
}
