import { Injectable, NotFoundException, ForbiddenException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

@Injectable()
export class ConsignmentService {
  constructor(private readonly prisma: PrismaService) {}

  async listConsignments(userId: number, role: string, page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const where = role === 'admin' || role === 'operator'
      ? {}
      : { consignor_id: BigInt(userId) };

    const [data, total] = await this.prisma.$transaction([
      this.prisma.consignments.findMany({
        where,
        select: {
          id: true, commission_bps: true, status: true,
          starts_at: true, ends_at: true, created_at: true,
          artworks: { select: { id: true, title: true, slug: true, medium: true } },
          galleries: { select: { id: true, name: true, slug: true } },
        },
        orderBy: [{ created_at: 'desc' }],
        skip,
        take: perPage,
      }),
      this.prisma.consignments.count({ where }),
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

  async getConsignment(consignmentId: number, userId: number, role: string) {
    const consignment = await this.prisma.consignments.findFirst({
      where: { id: BigInt(consignmentId) },
      include: {
        artworks: { select: { id: true, title: true, slug: true, medium: true } },
        galleries: { select: { id: true, name: true, slug: true } },
      },
    });
    if (!consignment) throw new NotFoundException(`Consignment ${consignmentId} not found`);
    if (role !== 'admin' && role !== 'operator' && Number(consignment.consignor_id) !== userId) {
      throw new ForbiddenException('Access denied');
    }
    return consignment;
  }

  async createConsignment(userId: number, dto: { artwork_id: number; gallery_id?: number; commission_bps?: number }) {
    return this.prisma.consignments.create({
      data: {
        artwork_id: BigInt(dto.artwork_id),
        owner_id: BigInt(userId),
        consignor_id: BigInt(userId),
        gallery_id: dto.gallery_id ? BigInt(dto.gallery_id) : null,
        commission_bps: dto.commission_bps ?? 0,
        status: 'draft',
      },
    });
  }

  async transition(consignmentId: number, userId: number, role: string, newStatus: string) {
    const c = await this.getConsignment(consignmentId, userId, role);
    return this.prisma.consignments.update({
      where: { id: (c as any).id },
      data: { status: newStatus as any },
      select: { id: true, status: true, updated_at: true },
    });
  }

  async createLot(consignmentId: number, userId: number, role: string, dto: { sale_mode?: string; starting_bid_cents?: number; reserve_price_cents?: number }) {
    const c = await this.getConsignment(consignmentId, userId, role);
    return this.prisma.art_lots.create({
      data: {
        artwork_id: (c as any).artwork_id,
        consignment_id: (c as any).id,
        consignor_id: BigInt(userId),
        gallery_id: (c as any).gallery_id ?? null,
        sale_mode: (dto.sale_mode as any) ?? 'auction',
        status: 'draft',
        starting_bid_cents: dto.starting_bid_cents ? BigInt(dto.starting_bid_cents) : null,
        reserve_price_cents: dto.reserve_price_cents ? BigInt(dto.reserve_price_cents) : null,
      },
      select: { id: true, status: true, sale_mode: true, created_at: true },
    });
  }
}
