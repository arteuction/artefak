import { Injectable, NotFoundException, ForbiddenException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

export interface ArtworksQuery {
  status?: string;
  medium?: string;
  page?: number;
  perPage?: number;
}

@Injectable()
export class ArtworkService {
  constructor(private readonly prisma: PrismaService) {}

  async listArtworks(query: ArtworksQuery = {}) {
    const { status, medium, page = 1, perPage = 20 } = query;
    const skip = (page - 1) * perPage;

    const where: Record<string, unknown> = { deleted_at: null };
    if (status) where['status'] = status;
    if (medium) where['medium'] = medium;

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

  async getArtwork(idOrSlug: string) {
    const byId = /^\d+$/.test(idOrSlug)
      ? await this.prisma.artworks.findFirst({ where: { id: BigInt(idOrSlug), deleted_at: null } })
      : null;

    const artwork = byId ?? await this.prisma.artworks.findFirst({
      where: { slug: idOrSlug, deleted_at: null },
    });

    if (!artwork) throw new NotFoundException(`Artwork "${idOrSlug}" not found`);
    return artwork;
  }

  async createArtwork(userId: number, dto: { title: string; slug: string; medium?: string; description?: string; year_created?: number; dimensions?: string }) {
    return this.prisma.artworks.create({
      data: {
        user_id: BigInt(userId),
        title: dto.title,
        slug: dto.slug,
        medium: (dto.medium as any) ?? 'painting',
        description: dto.description ?? null,
        year_created: dto.year_created ?? null,
        dimensions: dto.dimensions ?? null,
        status: 'draft',
      },
      select: { id: true, title: true, slug: true, medium: true, status: true, created_at: true },
    });
  }

  async updateArtwork(idOrSlug: string, userId: number, role: string, dto: { title?: string; description?: string; dimensions?: string; year_created?: number }) {
    const artwork = await this.getArtwork(idOrSlug);
    if (role !== 'admin' && Number(artwork.user_id) !== userId) throw new ForbiddenException();
    return this.prisma.artworks.update({
      where: { id: artwork.id },
      data: {
        ...(dto.title !== undefined ? { title: dto.title } : {}),
        ...(dto.description !== undefined ? { description: dto.description } : {}),
        ...(dto.dimensions !== undefined ? { dimensions: dto.dimensions } : {}),
        ...(dto.year_created !== undefined ? { year_created: dto.year_created } : {}),
      },
      select: { id: true, title: true, slug: true, status: true, updated_at: true },
    });
  }

  async submitArtwork(idOrSlug: string, userId: number, role: string) {
    const artwork = await this.getArtwork(idOrSlug);
    if (role !== 'admin' && Number(artwork.user_id) !== userId) throw new ForbiddenException();
    return this.prisma.artworks.update({
      where: { id: artwork.id },
      data: { status: 'review' as any },
      select: { id: true, status: true, updated_at: true },
    });
  }

  async listEvidence(idOrSlug: string) {
    const artwork = await this.getArtwork(idOrSlug);
    return this.prisma.artwork_evidence.findMany({
      where: { artwork_id: artwork.id },
      select: { id: true, type: true, issuer: true, verification_status: true, visibility: true, created_at: true },
      orderBy: [{ created_at: 'desc' }],
    });
  }

  async createEvidence(idOrSlug: string, _userId: number, dto: { type: string; issuer?: string; notes?: string }) {
    const artwork = await this.getArtwork(idOrSlug);
    return this.prisma.artwork_evidence.create({
      data: {
        artwork_id: artwork.id,
        type: dto.type as any,
        issuer: dto.issuer ?? null,
        notes: dto.notes ?? null,
      },
      select: { id: true, type: true, issuer: true, verification_status: true, created_at: true },
    });
  }

  async listSdgClaims(idOrSlug: string) {
    const artwork = await this.getArtwork(idOrSlug);
    return this.prisma.artwork_sdg_claims.findMany({
      where: { artwork_id: artwork.id },
      select: { id: true, sdg_number: true, status: true, created_at: true },
      orderBy: [{ created_at: 'desc' }],
    });
  }

  async createSdgClaim(idOrSlug: string, _userId: number, dto: { sdg_number: number; rationale: string }) {
    const artwork = await this.getArtwork(idOrSlug);
    return this.prisma.artwork_sdg_claims.create({
      data: {
        artwork_id: artwork.id,
        sdg_number: dto.sdg_number,
        rationale: dto.rationale,
        status: 'pending' as any,
      },
      select: { id: true, sdg_number: true, status: true, created_at: true },
    });
  }

  async listArtLots(artworkIdOrSlug: string) {
    const artwork = await this.getArtwork(artworkIdOrSlug);

    return this.prisma.art_lots.findMany({
      where: { artwork_id: artwork.id },
      select: {
        id: true, sale_mode: true, status: true,
        starting_bid_cents: true, buy_now_price_cents: true,
        reserve_price_cents: true, currency: true,
        estimate_low_cents: true, estimate_high_cents: true,
        published_at: true, closed_at: true,
      },
      orderBy: [{ created_at: 'desc' }],
    });
  }
}
