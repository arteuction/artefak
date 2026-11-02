import { Injectable } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

@Injectable()
export class ProfileService {
  constructor(private readonly prisma: PrismaService) {}

  async getUserBids(userId: number, page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;

    const [data, total] = await this.prisma.$transaction([
      this.prisma.bid.findMany({
        where: { user_id: BigInt(userId) },
        select: {
          id: true, amount_cents: true, status: true, bid_type: true, created_at: true,
          auction_item: {
            select: {
              id: true, lot_number: true,
              auction: { select: { id: true, title: true, slug: true } },
            },
          },
        },
        orderBy: [{ created_at: 'desc' }],
        skip,
        take: perPage,
      }),
      this.prisma.bid.count({ where: { user_id: BigInt(userId) } }),
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
}
