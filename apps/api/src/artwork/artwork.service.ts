import { Injectable, NotFoundException, ForbiddenException, BadRequestException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';
import { S3Service } from '../s3/s3.service';

export interface ArtworksQuery {
  status?: string;
  medium?: string;
  page?: number;
  perPage?: number;
}

@Injectable()
export class ArtworkService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly s3: S3Service,
  ) {}

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

  async getLinkedArt(idOrSlug: string) {
    const artwork = await this.getArtwork(idOrSlug);
    return { '@context': 'https://linked.art/ns/v1/linked-art.json', id: `/artworks/${artwork.id}`, type: 'HumanMadeObject' };
  }

  async getEdm(idOrSlug: string) {
    const artwork = await this.getArtwork(idOrSlug);
    return { type: 'ProvidedCHO', identifier: String(artwork.id) };
  }

  async getIiifManifest(idOrSlug: string) {
    const artwork = await this.getArtwork(idOrSlug);
    return { '@context': 'http://iiif.io/api/presentation/3/context.json', id: `/artworks/${artwork.id}/iiif/manifest`, type: 'Manifest' };
  }

  async getIiifInfo(idOrSlug: string) {
    const artwork = await this.getArtwork(idOrSlug);
    return { '@context': 'http://iiif.io/api/image/3/context.json', id: `/artworks/${artwork.id}/iiif/info.json`, type: 'ImageService3' };
  }

  async getRights(idOrSlug: string) {
    const artwork = await this.getArtwork(idOrSlug);
    const row = await this.prisma.artworks.findUnique({
      where: { id: artwork.id },
      select: { id: true, rights_statement: true, license_spdx: true, provenance: true },
    });
    return {
      artwork_id:       artwork.id,
      rights_statement: row?.rights_statement ?? null,
      license_spdx:     row?.license_spdx     ?? null,
      provenance:       row?.provenance        ?? null,
    };
  }

  async updateRights(idOrSlug: string, _userId: number, role: string, dto: { rights_statement?: string; license_spdx?: string; provenance?: string }) {
    if (role !== 'admin' && role !== 'operator') throw new ForbiddenException('Insufficient permissions');
    const artwork = await this.getArtwork(idOrSlug);
    const updated = await this.prisma.artworks.update({
      where: { id: artwork.id },
      data:  {
        ...(dto.rights_statement !== undefined ? { rights_statement: dto.rights_statement } : {}),
        ...(dto.license_spdx     !== undefined ? { license_spdx:     dto.license_spdx     } : {}),
        ...(dto.provenance       !== undefined ? { provenance:       dto.provenance       } : {}),
      },
      select: { id: true, rights_statement: true, license_spdx: true, provenance: true, updated_at: true },
    });
    return { artwork_id: artwork.id, ...updated };
  }

  async getQr(idOrSlug: string) {
    const artwork = await this.getArtwork(idOrSlug);
    return { artwork_id: artwork.id, qr_url: `/artworks/${artwork.id}/qr.png` };
  }

  async listRevisions(idOrSlug: string) {
    const artwork = await this.getArtwork(idOrSlug);
    return this.prisma.artwork_revisions.findMany({
      where: { artwork_id: artwork.id },
      select: { id: true, artwork_id: true, version: true, status: true, reason: true, created_at: true },
      orderBy: [{ created_at: 'desc' }],
    });
  }

  async createRevision(idOrSlug: string, userId: number, dto: { title: string; version?: number; medium?: string; description?: string; reason?: string }) {
    const artwork = await this.getArtwork(idOrSlug);
    const lastRevision = await this.prisma.artwork_revisions.findFirst({ where: { artwork_id: artwork.id }, orderBy: [{ version: 'desc' }] });
    const nextVersion = lastRevision ? Number(lastRevision.version) + 1 : 1;
    return this.prisma.artwork_revisions.create({
      data: {
        artwork_id: artwork.id,
        revised_by: BigInt(userId),
        version: nextVersion,
        title: dto.title,
        medium: dto.medium,
        description: dto.description,
        reason: (dto.reason as any) ?? 'correction',
      },
      select: { id: true, artwork_id: true, version: true, status: true, created_at: true },
    });
  }

  async activateRevision(idOrSlug: string, revisionId: number, _userId: number, role: string) {
    if (role !== 'admin' && role !== 'operator') throw new ForbiddenException('Insufficient permissions');
    const artwork = await this.getArtwork(idOrSlug);
    const revision = await this.prisma.artwork_revisions.findFirst({ where: { id: BigInt(revisionId), artwork_id: artwork.id } });
    if (!revision) throw new NotFoundException(`Revision ${revisionId} not found`);
    return this.prisma.artwork_revisions.update({
      where: { id: revision.id },
      data: { status: 'active' as any },
      select: { id: true, artwork_id: true, version: true, status: true, updated_at: true },
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

  async getSdgClaim(_artworkIdOrSlug: string, claimId: string) {
    const claim = await this.prisma.artwork_sdg_claims.findFirst({ where: { id: parseInt(claimId, 10) } });
    if (!claim) throw new NotFoundException(`SDG claim ${claimId} not found`);
    return claim;
  }

  async reviewSdgClaim(_artworkIdOrSlug: string, claimId: string, _userId: number, role: string, dto: { approved: boolean; notes?: string }) {
    if (role !== 'admin' && role !== 'operator') throw new ForbiddenException();
    const claim = await this.prisma.artwork_sdg_claims.findFirst({ where: { id: parseInt(claimId, 10) } });
    if (!claim) throw new NotFoundException(`SDG claim ${claimId} not found`);
    return this.prisma.artwork_sdg_claims.update({
      where: { id: claim.id },
      data: { status: (dto.approved ? 'approved' : 'rejected') as any },
      select: { id: true, sdg_number: true, status: true, updated_at: true },
    });
  }

  async getSalesHistory(artworkIdOrSlug: string) {
    const artwork = await this.getArtwork(artworkIdOrSlug);
    const data = await this.prisma.ownership_transfers.findMany({
      where: { art_lots: { artwork_id: artwork.id } },
      select: {
        id: true, transfer_price_cents: true, currency: true, channel: true,
        transferred_at: true, created_at: true,
      },
      orderBy: [{ transferred_at: 'desc' }],
    });
    return { data };
  }

  private static readonly ALLOWED_IMAGE_TYPES = new Set([
    'image/jpeg', 'image/png', 'image/webp', 'image/tiff',
  ]);
  private static readonly MAX_IMAGE_SIZE_BYTES = 50 * 1024 * 1024; // 50 MB

  async presignImage(artworkIdOrSlug: string, _userId: number, dto: { filename: string; content_type: string; size_bytes?: number }) {
    await this.getArtwork(artworkIdOrSlug);

    if (!ArtworkService.ALLOWED_IMAGE_TYPES.has(dto.content_type)) {
      throw new BadRequestException(
        `Unsupported image type "${dto.content_type}". Allowed: ${[...ArtworkService.ALLOWED_IMAGE_TYPES].join(', ')}`
      );
    }
    if (dto.size_bytes !== undefined && dto.size_bytes > ArtworkService.MAX_IMAGE_SIZE_BYTES) {
      throw new BadRequestException(`Image exceeds 50 MB limit (received ${dto.size_bytes} bytes)`);
    }

    const ext = dto.filename.split('.').pop() ?? 'bin';
    const key = `artworks/${artworkIdOrSlug}/images/${Date.now()}.${ext}`;
    const upload_url = this.s3.enabled
      ? await this.s3.presignUpload(key, dto.content_type)
      : this.s3.stubUploadUrl(key);
    return { upload_url, key, expires_in: 3600 };
  }

  async confirmImage(artworkIdOrSlug: string, _userId: number, dto: { key: string }) {
    const artwork = await this.getArtwork(artworkIdOrSlug);
    await this.prisma.artworks.update({
      where: { id: artwork.id },
      data: { primary_image_key: dto.key, primary_image_status: 'uploaded' as any, updated_at: new Date() },
    });
    return { confirmed: true, updated_at: new Date().toISOString() };
  }

  async generateDerivatives(artworkIdOrSlug: string, _userId: number, role: string) {
    if (role !== 'admin' && role !== 'operator') throw new ForbiddenException();
    const artwork = await this.getArtwork(artworkIdOrSlug);
    if (!artwork) throw new NotFoundException(`Artwork not found: ${artworkIdOrSlug}`);

    await this.prisma.domain_events.create({
      data: {
        event_type: 'artwork.derivatives_requested',
        aggregate_type: 'artwork',
        aggregate_id: artwork.id,
        idempotency_key: `artwork.derivatives_requested:${String(artwork.id)}:${Date.now()}`,
        payload: JSON.stringify({ artwork_id: Number(artwork.id), slug: artwork.slug }),
        status: 'pending' as any,
        attempt: 0,
        next_attempt_at: new Date(),
      },
    });

    return { queued: true };
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
