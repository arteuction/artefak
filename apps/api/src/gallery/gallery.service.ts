import { Injectable, NotFoundException, ForbiddenException } from '@nestjs/common';
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

  async getGalleryProfile(idOrSlug: string) {
    const gallery = await this.getGallery(idOrSlug);
    return this.prisma.galleries.findUnique({
      where: { id: gallery.id },
      include: { gallery_staff: { where: { status: 'active' }, select: { id: true, role: true, user_id: true } } },
    });
  }

  async createGallery(dto: { name: string; slug: string; type?: string; website?: string; contact_email?: string; legal_name?: string }) {
    return this.prisma.galleries.create({
      data: {
        name: dto.name,
        slug: dto.slug,
        type: (dto.type as any) ?? 'private',
        website: dto.website,
        contact_email: dto.contact_email,
        legal_name: dto.legal_name,
      },
      select: { id: true, name: true, slug: true, type: true, status: true, created_at: true },
    });
  }

  async updateGallery(idOrSlug: string, _userId: number, role: string, dto: { name?: string; website?: string; contact_email?: string; legal_name?: string }) {
    if (role !== 'admin' && role !== 'operator') throw new ForbiddenException('Insufficient permissions');
    const gallery = await this.getGallery(idOrSlug);
    return this.prisma.galleries.update({
      where: { id: gallery.id },
      data: dto as any,
      select: { id: true, name: true, slug: true, type: true, status: true, updated_at: true },
    });
  }

  async listStaff(idOrSlug: string) {
    const gallery = await this.getGallery(idOrSlug);
    return this.prisma.gallery_staff.findMany({
      where: { gallery_id: gallery.id },
      select: { id: true, role: true, status: true, user_id: true, invited_at: true, accepted_at: true },
    });
  }

  async addStaff(idOrSlug: string, _userId: number, role: string, dto: { user_id: number; role: string }) {
    if (role !== 'admin' && role !== 'operator') throw new ForbiddenException('Insufficient permissions');
    const gallery = await this.getGallery(idOrSlug);
    return this.prisma.gallery_staff.create({
      data: { gallery_id: gallery.id, user_id: BigInt(dto.user_id), role: dto.role, invited_at: new Date() },
      select: { id: true, gallery_id: true, user_id: true, role: true, status: true, invited_at: true },
    });
  }

  async updateStaff(idOrSlug: string, staffId: number, _userId: number, role: string, dto: { role?: string; status?: string }) {
    if (role !== 'admin' && role !== 'operator') throw new ForbiddenException('Insufficient permissions');
    const gallery = await this.getGallery(idOrSlug);
    const staff = await this.prisma.gallery_staff.findFirst({ where: { id: BigInt(staffId), gallery_id: gallery.id } });
    if (!staff) throw new NotFoundException(`Staff member ${staffId} not found`);
    return this.prisma.gallery_staff.update({
      where: { id: staff.id },
      data: dto as any,
      select: { id: true, role: true, status: true, updated_at: true },
    });
  }

  async removeStaff(idOrSlug: string, staffUserId: number, _userId: number, role: string) {
    if (role !== 'admin' && role !== 'operator') throw new ForbiddenException('Insufficient permissions');
    const gallery = await this.getGallery(idOrSlug);
    const staff = await this.prisma.gallery_staff.findFirst({ where: { gallery_id: gallery.id, user_id: BigInt(staffUserId) } });
    if (!staff) throw new NotFoundException(`Staff member ${staffUserId} not found`);
    await this.prisma.gallery_staff.delete({ where: { id: staff.id } });
    return { deleted: true };
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
