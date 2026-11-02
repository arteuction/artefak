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
}
