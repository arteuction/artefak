import { Injectable, NotFoundException, ForbiddenException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

export interface CollectionsQuery {
  page?: number;
  perPage?: number;
}

@Injectable()
export class CollectionService {
  constructor(private readonly prisma: PrismaService) {}

  async listPublicCollections(query: CollectionsQuery = {}) {
    const { page = 1, perPage = 20 } = query;
    const skip = (page - 1) * perPage;

    const where = { visibility: 'public' };

    const [data, total] = await this.prisma.$transaction([
      this.prisma.collections.findMany({
        where,
        select: {
          id: true, title: true, slug: true, description: true,
          cover_image_url: true, visibility: true, created_at: true,
        },
        orderBy: [{ created_at: 'desc' }],
        skip,
        take: perPage,
      }),
      this.prisma.collections.count({ where }),
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

  async getCollection(idOrSlug: string) {
    const byId = /^\d+$/.test(idOrSlug)
      ? await this.prisma.collections.findFirst({ where: { id: BigInt(idOrSlug), visibility: 'public' } })
      : null;

    const collection = byId ?? await this.prisma.collections.findFirst({
      where: { slug: idOrSlug, visibility: 'public' },
    });

    if (!collection) throw new NotFoundException(`Collection "${idOrSlug}" not found`);
    return collection;
  }

  async getCollectionArtworks(idOrSlug: string) {
    const collection = await this.getCollection(idOrSlug);

    const items = await this.prisma.collection_artworks.findMany({
      where: { collection_id: collection.id },
      select: {
        artworks: {
          select: {
            id: true, title: true, slug: true, medium: true, status: true,
            primary_image_key: true, year_created: true,
          },
        },
        position: true,
      },
      orderBy: [{ position: 'asc' }],
    });

    return items.map((item) => ({ ...item.artworks, position: item.position }));
  }

  async createCollection(userId: number, dto: { title: string; slug: string; description?: string; visibility?: string }) {
    return this.prisma.collections.create({
      data: {
        owner_id: BigInt(userId),
        title: dto.title,
        slug: dto.slug,
        description: dto.description ?? null,
        visibility: (dto.visibility as any) ?? 'public',
      },
      select: { id: true, title: true, slug: true, visibility: true, created_at: true },
    });
  }

  async updateCollection(idOrSlug: string, userId: number, role: string, dto: { title?: string; description?: string; visibility?: string }) {
    const coll = await this.prisma.collections.findFirst({
      where: /^\d+$/.test(idOrSlug) ? { id: BigInt(idOrSlug) } : { slug: idOrSlug },
    });
    if (!coll) throw new NotFoundException(`Collection "${idOrSlug}" not found`);
    if (role !== 'admin' && Number(coll.owner_id) !== userId) throw new ForbiddenException();
    return this.prisma.collections.update({
      where: { id: coll.id },
      data: {
        ...(dto.title !== undefined ? { title: dto.title } : {}),
        ...(dto.description !== undefined ? { description: dto.description } : {}),
        ...(dto.visibility !== undefined ? { visibility: dto.visibility as any } : {}),
      },
      select: { id: true, title: true, slug: true, visibility: true, updated_at: true },
    });
  }

  async deleteCollection(idOrSlug: string, userId: number, role: string) {
    const coll = await this.prisma.collections.findFirst({
      where: /^\d+$/.test(idOrSlug) ? { id: BigInt(idOrSlug) } : { slug: idOrSlug },
    });
    if (!coll) throw new NotFoundException(`Collection "${idOrSlug}" not found`);
    if (role !== 'admin' && Number(coll.owner_id) !== userId) throw new ForbiddenException();
    await this.prisma.collections.delete({ where: { id: coll.id } });
    return { deleted: true };
  }

  async addArtwork(idOrSlug: string, userId: number, role: string, artworkId: number) {
    const coll = await this.prisma.collections.findFirst({
      where: /^\d+$/.test(idOrSlug) ? { id: BigInt(idOrSlug) } : { slug: idOrSlug },
    });
    if (!coll) throw new NotFoundException(`Collection "${idOrSlug}" not found`);
    if (role !== 'admin' && Number(coll.owner_id) !== userId) throw new ForbiddenException();
    const maxPos = await this.prisma.collection_artworks.aggregate({ where: { collection_id: coll.id }, _max: { position: true } });
    const position = (maxPos._max.position ?? 0) + 1;
    return this.prisma.collection_artworks.create({
      data: { collection_id: coll.id, artwork_id: BigInt(artworkId), position },
      select: { id: true, collection_id: true, artwork_id: true, position: true },
    });
  }

  async removeArtwork(idOrSlug: string, userId: number, role: string, artworkId: number) {
    const coll = await this.prisma.collections.findFirst({
      where: /^\d+$/.test(idOrSlug) ? { id: BigInt(idOrSlug) } : { slug: idOrSlug },
    });
    if (!coll) throw new NotFoundException(`Collection "${idOrSlug}" not found`);
    if (role !== 'admin' && Number(coll.owner_id) !== userId) throw new ForbiddenException();
    const item = await this.prisma.collection_artworks.findFirst({ where: { collection_id: coll.id, artwork_id: BigInt(artworkId) } });
    if (!item) throw new NotFoundException(`Artwork ${artworkId} not in collection`);
    await this.prisma.collection_artworks.delete({ where: { id: item.id } });
    return { deleted: true };
  }
}
