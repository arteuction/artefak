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

  async assignDispute(disputeId: number, _userId: number, role: string, assigneeId: number) {
    if (role !== 'admin' && role !== 'operator') throw new ForbiddenException('Insufficient permissions');
    const dispute = await this.prisma.disputes.findFirst({ where: { id: BigInt(disputeId) } });
    if (!dispute) throw new NotFoundException(`Dispute ${disputeId} not found`);
    return this.prisma.disputes.update({
      where: { id: dispute.id },
      data: { assigned_to: BigInt(assigneeId) },
      select: { id: true, status: true, assigned_to: true, updated_at: true },
    });
  }

  async resolveDispute(disputeId: number, userId: number, role: string, dto: { resolution: string }) {
    if (role !== 'admin' && role !== 'operator') throw new ForbiddenException('Insufficient permissions');
    const dispute = await this.prisma.disputes.findFirst({ where: { id: BigInt(disputeId) } });
    if (!dispute) throw new NotFoundException(`Dispute ${disputeId} not found`);
    return this.prisma.disputes.update({
      where: { id: dispute.id },
      data: { status: 'resolved', resolution: dto.resolution, resolved_at: new Date(), resolved_by: BigInt(userId) },
      select: { id: true, status: true, resolution: true, resolved_at: true },
    });
  }

  async attachEvidence(disputeId: number, userId: number, role: string, dto: { reason: string }) {
    if (role !== 'admin' && role !== 'operator') throw new ForbiddenException('Insufficient permissions');
    const dispute = await this.prisma.disputes.findFirst({ where: { id: BigInt(disputeId) } });
    if (!dispute) throw new NotFoundException(`Dispute ${disputeId} not found`);
    return this.prisma.dispute_escalations.create({
      data: { dispute_id: dispute.id, reason: dto.reason, escalated_by: BigInt(userId) },
      select: { id: true, dispute_id: true, reason: true, created_at: true },
    });
  }
}
