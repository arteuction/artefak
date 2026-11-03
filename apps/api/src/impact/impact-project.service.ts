import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

@Injectable()
export class ImpactProjectService {
  constructor(private readonly prisma: PrismaService) {}

  async getStats() {
    const [total, funded] = await this.prisma.$transaction([
      this.prisma.impact_projects.count(),
      this.prisma.impact_projects.count({ where: { status: 'funded' } }),
    ]);
    return { total: Number(total), funded: Number(funded) };
  }

  async listProjects(page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const [data, total] = await this.prisma.$transaction([
      this.prisma.impact_projects.findMany({
        select: {
          id: true, title: true, slug: true, sdg_number: true,
          funding_target_cents: true, funding_actual_cents: true,
          currency: true, status: true, starts_on: true, ends_on: true,
          created_at: true,
        },
        orderBy: [{ created_at: 'desc' }],
        skip,
        take: perPage,
      }),
      this.prisma.impact_projects.count(),
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

  async getProject(idOrSlug: string) {
    const project = /^\d+$/.test(idOrSlug)
      ? await this.prisma.impact_projects.findFirst({ where: { id: BigInt(idOrSlug) } })
      : await this.prisma.impact_projects.findFirst({ where: { slug: idOrSlug } });

    if (!project) throw new NotFoundException(`ImpactProject "${idOrSlug}" not found`);
    return project;
  }

  async createProject(dto: { title: string; slug: string; sdg_number: number; funding_target_cents: number; currency?: string; description?: string; starts_on?: string; ends_on?: string }) {
    return this.prisma.impact_projects.create({
      data: {
        title:                dto.title,
        slug:                 dto.slug,
        sdg_number:           dto.sdg_number,
        funding_target_cents: BigInt(dto.funding_target_cents),
        funding_actual_cents: BigInt(0),
        currency:             dto.currency ?? 'EUR',
        description:          dto.description ?? null,
        starts_on:            dto.starts_on ? new Date(dto.starts_on) : null,
        ends_on:              dto.ends_on   ? new Date(dto.ends_on)   : null,
      },
      select: {
        id: true, title: true, slug: true, sdg_number: true,
        funding_target_cents: true, currency: true, status: true, created_at: true,
      },
    });
  }

  async updateProject(idOrSlug: string, dto: { title?: string; description?: string; status?: string; ends_on?: string }) {
    const project = await this.getProject(idOrSlug);
    return this.prisma.impact_projects.update({
      where: { id: project.id },
      data: {
        ...(dto.title       !== undefined ? { title:       dto.title             } : {}),
        ...(dto.description !== undefined ? { description: dto.description       } : {}),
        ...(dto.status      !== undefined ? { status:      dto.status as any     } : {}),
        ...(dto.ends_on     !== undefined ? { ends_on:     new Date(dto.ends_on) } : {}),
      },
      select: {
        id: true, title: true, slug: true, status: true, updated_at: true,
      },
    });
  }
}
