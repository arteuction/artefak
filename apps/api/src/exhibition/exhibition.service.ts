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

  async createExhibition(dto: { title: string; slug: string; venue_id: number; starts_at: string; ends_at: string; description?: string }) {
    return this.prisma.exhibitions.create({
      data: {
        title: dto.title,
        slug: dto.slug,
        venue_id: BigInt(dto.venue_id),
        starts_at: new Date(dto.starts_at),
        ends_at: new Date(dto.ends_at),
        description: dto.description,
      },
      select: { id: true, title: true, slug: true, is_active: true, starts_at: true, ends_at: true, created_at: true },
    });
  }

  async updateExhibition(idOrSlug: string, dto: { title?: string; description?: string; is_active?: boolean; starts_at?: string; ends_at?: string }) {
    const exhibition = await this.getExhibition(idOrSlug);
    const data: Record<string, unknown> = {};
    if (dto.title) data['title'] = dto.title;
    if (dto.description !== undefined) data['description'] = dto.description;
    if (dto.is_active !== undefined) data['is_active'] = dto.is_active;
    if (dto.starts_at) data['starts_at'] = new Date(dto.starts_at);
    if (dto.ends_at) data['ends_at'] = new Date(dto.ends_at);
    return this.prisma.exhibitions.update({
      where: { id: exhibition.id },
      data,
      select: { id: true, title: true, slug: true, is_active: true, updated_at: true },
    });
  }

  async tagSdgs(idOrSlug: string, sdgNumbers: number[]) {
    const exhibition = await this.getExhibition(idOrSlug);
    await this.prisma.artmetro_exhibition_sdg.deleteMany({ where: { exhibition_id: exhibition.id } });
    if (sdgNumbers.length > 0) {
      await this.prisma.artmetro_exhibition_sdg.createMany({
        data: sdgNumbers.map(n => ({ exhibition_id: exhibition.id, sdg_number: n })),
      });
    }
    return { exhibition_id: exhibition.id, sdg_numbers: sdgNumbers };
  }
}
