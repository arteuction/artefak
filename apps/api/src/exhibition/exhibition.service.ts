import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

@Injectable()
export class ExhibitionService {
  constructor(private readonly prisma: PrismaService) {}

  async listExhibitions(page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const [data, total] = await this.prisma.$transaction([
      this.prisma.exhibitions.findMany({
        select: {
          id: true, title: true, slug: true, description: true,
          starts_at: true, ends_at: true, is_active: true, created_at: true,
        },
        orderBy: [{ starts_at: 'desc' }],
        skip,
        take: perPage,
      }),
      this.prisma.exhibitions.count(),
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

  async getExhibition(idOrSlug: string) {
    const exhibition = /^\d+$/.test(idOrSlug)
      ? await this.prisma.exhibitions.findFirst({ where: { id: BigInt(idOrSlug) } })
      : await this.prisma.exhibitions.findFirst({ where: { slug: idOrSlug } });

    if (!exhibition) throw new NotFoundException(`Exhibition "${idOrSlug}" not found`);
    return exhibition;
  }
}
