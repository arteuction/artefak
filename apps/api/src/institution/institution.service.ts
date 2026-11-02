import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

@Injectable()
export class InstitutionService {
  constructor(private readonly prisma: PrismaService) {}

  async listInstitutions(page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const where = { deleted_at: null };
    const [data, total] = await this.prisma.$transaction([
      this.prisma.institutions.findMany({
        where,
        select: { id: true, name: true, slug: true, type: true, country_code: true, website_url: true, is_active: true, created_at: true },
        orderBy: [{ name: 'asc' }],
        skip,
        take: perPage,
      }),
      this.prisma.institutions.count({ where }),
    ]);
    return { data, meta: { current_page: page, last_page: Math.max(1, Math.ceil(total / perPage)), per_page: perPage, total } };
  }

  async getInstitution(idOrSlug: string) {
    const numId = Number(idOrSlug);
    const where = isNaN(numId) ? { slug: idOrSlug } : { id: BigInt(numId) };
    const inst = await this.prisma.institutions.findFirst({ where: { ...where, deleted_at: null }, select: { id: true, name: true, slug: true, type: true, country_code: true, website_url: true, contact_email: true, is_active: true, created_at: true } });
    if (!inst) throw new NotFoundException(`Institution ${idOrSlug} not found`);
    return inst;
  }

  async createInstitution(dto: { name: string; slug: string; type?: string; country_code?: string; website_url?: string; contact_email?: string }) {
    return this.prisma.institutions.create({
      data: {
        name: dto.name,
        slug: dto.slug,
        type: (dto.type as any) ?? 'other',
        country_code: dto.country_code ?? null,
        website_url: dto.website_url ?? null,
        contact_email: dto.contact_email ?? null,
      },
      select: { id: true, name: true, slug: true, type: true, country_code: true, created_at: true },
    });
  }

  async updateInstitution(idOrSlug: string, dto: { name?: string; country_code?: string; website_url?: string; contact_email?: string; is_active?: boolean }) {
    const inst = await this.getInstitution(idOrSlug);
    return this.prisma.institutions.update({
      where: { id: (inst as any).id },
      data: {
        ...(dto.name !== undefined ? { name: dto.name } : {}),
        ...(dto.country_code !== undefined ? { country_code: dto.country_code } : {}),
        ...(dto.website_url !== undefined ? { website_url: dto.website_url } : {}),
        ...(dto.contact_email !== undefined ? { contact_email: dto.contact_email } : {}),
        ...(dto.is_active !== undefined ? { is_active: dto.is_active } : {}),
      },
      select: { id: true, name: true, slug: true, type: true, country_code: true, is_active: true, created_at: true },
    });
  }

  async attachArtwork(institutionId: string, artworkId: number) {
    const inst = await this.getInstitution(institutionId);
    return this.prisma.institution_artworks.create({
      data: { institution_id: (inst as any).id, artwork_id: BigInt(artworkId) },
      select: { id: true, institution_id: true, artwork_id: true },
    });
  }
}
