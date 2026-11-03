import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

export interface CreateReserveDto {
  auction_item_id: number;
  reserve_price_cents: number;
  highest_bid_cents?: number;
}

@Injectable()
export class ReserveService {
  constructor(private readonly prisma: PrismaService) {}

  async listReserves(auctionItemId?: number) {
    return this.prisma.reserves.findMany({
      where: auctionItemId ? { auction_item_id: BigInt(auctionItemId) } : undefined,
      select: { id: true, auction_item_id: true, reserve_price_cents: true, highest_bid_cents: true, status: true, created_at: true },
      orderBy: [{ created_at: 'desc' }],
    });
  }

  async createReserve(dto: CreateReserveDto) {
    return this.prisma.reserves.create({
      data: {
        auction_item_id:     BigInt(dto.auction_item_id),
        reserve_price_cents: BigInt(dto.reserve_price_cents),
        highest_bid_cents:   BigInt(dto.highest_bid_cents ?? 0),
      },
      select: { id: true, auction_item_id: true, reserve_price_cents: true, highest_bid_cents: true, status: true, created_at: true },
    });
  }

  async getReserve(reserveId: number) {
    const reserve = await this.prisma.reserves.findFirst({
      where: { id: BigInt(reserveId) },
    });
    if (!reserve) throw new NotFoundException(`Reserve ${reserveId} not found`);
    return reserve;
  }

  async updateReserve(reserveId: number, dto: { reserve_price_cents?: number; counter_offer_cents?: number; notes?: string }) {
    const reserve = await this.getReserve(reserveId);
    return this.prisma.reserves.update({
      where: { id: reserve.id },
      data: {
        ...(dto.reserve_price_cents  !== undefined ? { reserve_price_cents:  BigInt(dto.reserve_price_cents)  } : {}),
        ...(dto.counter_offer_cents  !== undefined ? { counter_offer_cents:  BigInt(dto.counter_offer_cents)  } : {}),
        ...(dto.notes                !== undefined ? { notes:                dto.notes                        } : {}),
      },
      select: { id: true, auction_item_id: true, reserve_price_cents: true, counter_offer_cents: true, status: true, updated_at: true },
    });
  }
}
