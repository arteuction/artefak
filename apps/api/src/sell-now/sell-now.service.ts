import { Injectable, NotFoundException, BadRequestException } from '@nestjs/common';
import { art_lots_status } from '@prisma/client';
import { PrismaService } from '../prisma.service';
import { StripeService } from '../stripe/stripe.service';

export interface CreateSellNowOfferDto {
  offered_price_cents: number;
  currency?: string;
  notes?: string;
}

@Injectable()
export class SellNowService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly stripe: StripeService,
  ) {}

  async createOffer(artLotId: number, buyerId: number, dto: CreateSellNowOfferDto) {
    if (!Number.isInteger(dto.offered_price_cents) || dto.offered_price_cents <= 0) {
      throw new BadRequestException('offered_price_cents must be a positive integer');
    }

    const lot = await this.prisma.art_lots.findFirst({
      where: { id: BigInt(artLotId), status: art_lots_status.active },
    });
    if (!lot) throw new NotFoundException(`Art lot ${artLotId} not found or not available`);

    return this.prisma.sell_now_offers.create({
      data: {
        art_lot_id: BigInt(artLotId),
        buyer_id: BigInt(buyerId),
        offered_price_cents: BigInt(dto.offered_price_cents),
        currency: dto.currency ?? lot.currency,
        notes: dto.notes ?? null,
      },
      select: {
        id: true, art_lot_id: true, buyer_id: true,
        offered_price_cents: true, currency: true, status: true,
        notes: true, created_at: true,
      },
    });
  }

  async listUserOffers(userId: number, page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const where = { buyer_id: BigInt(userId) };

    const [data, total] = await this.prisma.$transaction([
      this.prisma.sell_now_offers.findMany({
        where,
        select: {
          id: true, offered_price_cents: true, counter_price_cents: true,
          agreed_price_cents: true, currency: true, status: true,
          expires_at: true, created_at: true,
          art_lots: {
            select: {
              id: true, currency: true,
              artworks: { select: { id: true, title: true, slug: true } },
            },
          },
        },
        orderBy: [{ created_at: 'desc' }],
        skip,
        take: perPage,
      }),
      this.prisma.sell_now_offers.count({ where }),
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

  async counterOffer(offerId: number, userId: number, counterPriceCents: number) {
    if (!Number.isInteger(counterPriceCents) || counterPriceCents <= 0) {
      throw new BadRequestException('counter_price_cents must be a positive integer');
    }
    const offer = await this.prisma.sell_now_offers.findFirst({ where: { id: BigInt(offerId) } });
    if (!offer) throw new NotFoundException(`Offer ${offerId} not found`);
    return this.prisma.sell_now_offers.update({
      where: { id: BigInt(offerId) },
      data: { counter_price_cents: BigInt(counterPriceCents), status: 'countered' as any },
      select: { id: true, status: true, counter_price_cents: true, updated_at: true },
    });
  }

  async acceptOffer(offerId: number, _userId: number) {
    const offer = await this.prisma.sell_now_offers.findFirst({ where: { id: BigInt(offerId) } });
    if (!offer) throw new NotFoundException(`Offer ${offerId} not found`);
    return this.prisma.sell_now_offers.update({
      where: { id: BigInt(offerId) },
      data: { status: 'accepted' as any, agreed_price_cents: offer.offered_price_cents },
      select: { id: true, status: true, agreed_price_cents: true, updated_at: true },
    });
  }

  async rejectOffer(offerId: number, _userId: number) {
    const offer = await this.prisma.sell_now_offers.findFirst({ where: { id: BigInt(offerId) } });
    if (!offer) throw new NotFoundException(`Offer ${offerId} not found`);
    return this.prisma.sell_now_offers.update({
      where: { id: BigInt(offerId) },
      data: { status: 'rejected' as any },
      select: { id: true, status: true, updated_at: true },
    });
  }

  async checkoutSession(offerId: number, buyerId: number) {
    const offer = await this.prisma.sell_now_offers.findFirst({
      where: { id: BigInt(offerId), buyer_id: BigInt(buyerId), status: 'accepted' as any },
      include: { art_lots: { include: { artworks: { select: { title: true } } } } },
    });
    if (!offer) throw new NotFoundException(`Accepted offer ${offerId} not found`);

    const baseUrl = process.env.APP_URL ?? 'https://arteuction.com';
    return this.stripe.createCheckoutSession({
      lineItems: [{
        name: offer.art_lots.artworks?.title ?? 'Artwork Purchase',
        amount_cents: Number(offer.agreed_price_cents ?? offer.offered_price_cents),
        currency: offer.currency,
      }],
      successUrl: `${baseUrl}/purchases?session_id={CHECKOUT_SESSION_ID}`,
      cancelUrl: `${baseUrl}/offers/${offerId}`,
      clientReferenceId: `sell_now_offer_${offerId}`,
      metadata: { offer_id: String(offerId), buyer_id: String(buyerId) },
    });
  }

  async getOffer(offerId: number, buyerId: number) {
    const offer = await this.prisma.sell_now_offers.findFirst({
      where: { id: BigInt(offerId), buyer_id: BigInt(buyerId) },
      select: {
        id: true, offered_price_cents: true, counter_price_cents: true,
        agreed_price_cents: true, currency: true, status: true,
        expires_at: true, notes: true, created_at: true, updated_at: true,
        art_lots: {
          select: {
            id: true, currency: true,
            artworks: { select: { id: true, title: true, slug: true, medium: true } },
          },
        },
      },
    });
    if (!offer) throw new NotFoundException(`Offer ${offerId} not found`);
    return offer;
  }
}
