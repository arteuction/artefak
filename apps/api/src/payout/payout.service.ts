import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

@Injectable()
export class PayoutService {
  constructor(private readonly prisma: PrismaService) {}

  /** Artist's payout history — settlement lines where they are the recipient. */
  async artistPayouts(userId: number, page = 1, perPage = 20) {
    const skip  = (page - 1) * perPage;
    const where = { user_id: BigInt(userId), recipient_type: 'artist' as any };

    const [data, total] = await this.prisma.$transaction([
      this.prisma.settlement_lines.findMany({
        where,
        include: {
          settlements: {
            select: {
              id: true, gross_cents: true, currency: true,
              profile_key: true, status: true, created_at: true,
              stripe_payment_intent_id: true,
            },
          },
        },
        orderBy: { id: 'desc' },
        skip,
        take: perPage,
      }),
      this.prisma.settlement_lines.count({ where }),
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

  /** Gallery's payout history — settlement lines for the gallery's legal entity. */
  async galleryPayouts(galleryId: number, page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;

    // Resolve legal_entity_id from gallery record
    const gallery = await this.prisma.galleries.findUnique({
      where: { id: BigInt(galleryId) },
      select: { id: true },
    });
    if (!gallery) throw new NotFoundException(`Gallery ${galleryId} not found`);

    const where = {
      legal_entity_id: gallery.id,
      recipient_type:  'gallery' as any,
    };

    const [data, total] = await this.prisma.$transaction([
      this.prisma.settlement_lines.findMany({
        where,
        include: {
          settlements: {
            select: {
              id: true, gross_cents: true, currency: true,
              profile_key: true, status: true, created_at: true,
              stripe_payment_intent_id: true,
            },
          },
        },
        orderBy: { id: 'desc' },
        skip,
        take: perPage,
      }),
      this.prisma.settlement_lines.count({ where }),
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

  /** Pending transfer-outbox rows — items awaiting Stripe dispatch. */
  async pendingTransfers(page = 1, perPage = 20) {
    const skip  = (page - 1) * perPage;
    const where = { status: 'pending' as any };

    const [data, total] = await this.prisma.$transaction([
      this.prisma.transfer_outbox.findMany({
        where,
        orderBy: [{ next_attempt_at: 'asc' }, { id: 'asc' }],
        skip,
        take: perPage,
      }),
      this.prisma.transfer_outbox.count({ where }),
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
