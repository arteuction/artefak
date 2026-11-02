import { Injectable, NotFoundException, ForbiddenException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

@Injectable()
export class DisputeService {
  constructor(private readonly prisma: PrismaService) {}

  async listDisputes(userId: number, role: string, page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    // Admin/operator sees all; regular user sees disputes they opened
    const where = role === 'admin' || role === 'operator'
      ? {}
      : { opened_by: BigInt(userId) };

    const [data, total] = await this.prisma.$transaction([
      this.prisma.disputes.findMany({
        where,
        select: {
          id: true, type: true, status: true, description: true,
          art_lot_id: true, auction_item_id: true, sell_now_offer_id: true,
          assigned_to: true, resolved_at: true, created_at: true,
        },
        orderBy: [{ created_at: 'desc' }],
        skip,
        take: perPage,
      }),
      this.prisma.disputes.count({ where }),
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

  async getDispute(disputeId: number, userId: number, role: string) {
    const dispute = await this.prisma.disputes.findFirst({
      where: { id: BigInt(disputeId) },
    });
    if (!dispute) throw new NotFoundException(`Dispute ${disputeId} not found`);
    if (role !== 'admin' && role !== 'operator' && Number(dispute.opened_by) !== userId) {
      throw new ForbiddenException('Access denied');
    }
    return dispute;
  }

  async createDispute(userId: number, dto: { type: string; description: string; art_lot_id?: number }) {
    return this.prisma.disputes.create({
      data: {
        type: dto.type,
        description: dto.description,
        opened_by: BigInt(userId),
        art_lot_id: dto.art_lot_id ? BigInt(dto.art_lot_id) : null,
        status: 'open',
      },
    });
  }
}
