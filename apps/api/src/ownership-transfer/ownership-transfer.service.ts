import { Injectable, NotFoundException, ForbiddenException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

@Injectable()
export class OwnershipTransferService {
  constructor(private readonly prisma: PrismaService) {}

  async listTransfers(userId: number, role: string, page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const where = role === 'admin' || role === 'operator'
      ? {}
      : { to_user_id: BigInt(userId) };

    const [data, total] = await this.prisma.$transaction([
      this.prisma.ownership_transfers.findMany({
        where,
        select: {
          id: true, transfer_price_cents: true, currency: true,
          channel: true, transferred_at: true, created_at: true,
          art_lot_id: true, auction_item_id: true, sell_now_offer_id: true,
        },
        orderBy: [{ transferred_at: 'desc' }],
        skip,
        take: perPage,
      }),
      this.prisma.ownership_transfers.count({ where }),
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

  async getTransfer(transferId: number, userId: number, role: string) {
    const transfer = await this.prisma.ownership_transfers.findFirst({
      where: { id: BigInt(transferId) },
    });
    if (!transfer) throw new NotFoundException(`OwnershipTransfer ${transferId} not found`);
    if (role !== 'admin' && role !== 'operator' && Number(transfer.to_user_id) !== userId) {
      throw new ForbiddenException('Access denied');
    }
    return transfer;
  }
}
