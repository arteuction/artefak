import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';
import { SettlementCalculator } from './settlement-calculator';
import { SplitProfile } from './split-profile';
import { Money } from './money';
import { CreateSettlement } from './create-settlement';
import { RecipientLine } from './recipient-line';

export interface CreateSettlementDto {
  grossCents:      number;
  currency?:       string;
  profileKey:      string;
  paymentIntent:   string;
  stripeEvent:     string;
  recipients:      RecipientLine[];
  auctionId?:      number;
  bookPurchaseId?: number;
}

@Injectable()
export class SettlementService {
  private readonly calc = new SettlementCalculator();

  constructor(
    private readonly prisma:  PrismaService,
    private readonly writer:  CreateSettlement,
  ) {}

  /** Calculate + persist one settlement atomically. Returns the settlement id. */
  async settle(dto: CreateSettlementDto): Promise<number> {
    const gross   = Money.fromCents(dto.grossCents, dto.currency ?? 'EUR');
    const profile = SplitProfile.fromKey(dto.profileKey);
    const result  = this.calc.calculate(gross, profile);

    return this.writer.execute(
      result,
      dto.paymentIntent,
      dto.stripeEvent,
      dto.recipients,
      dto.auctionId,
      dto.bookPurchaseId,
    );
  }

  async getSettlement(id: number) {
    const s = await this.prisma.settlements.findUnique({
      where: { id: BigInt(id) },
      include: { settlement_lines: true, ledger_entries: true },
    });
    if (!s) throw new NotFoundException(`Settlement ${id} not found`);
    return s;
  }

  async listSettlements(page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const [data, total] = await this.prisma.$transaction([
      this.prisma.settlements.findMany({
        skip,
        take: perPage,
        orderBy: { created_at: 'desc' },
        include: { settlement_lines: { select: { id: true, recipient_type: true, amount_cents: true, status: true } } },
      }),
      this.prisma.settlements.count(),
    ]);
    return {
      data,
      meta: { current_page: page, last_page: Math.ceil(total / perPage) || 1, per_page: perPage, total },
    };
  }

  async getSettlementLines(settlementId: number) {
    const lines = await this.prisma.settlement_lines.findMany({
      where: { settlement_id: BigInt(settlementId) },
      orderBy: { id: 'asc' },
    });
    if (lines.length === 0) {
      const exists = await this.prisma.settlements.findUnique({ where: { id: BigInt(settlementId) }, select: { id: true } });
      if (!exists) throw new NotFoundException(`Settlement ${settlementId} not found`);
    }
    return lines;
  }

  async getLedgerEntries(settlementId: number) {
    return this.prisma.ledger_entries.findMany({
      where: { settlement_id: BigInt(settlementId) },
      orderBy: { id: 'asc' },
    });
  }

  async getTransferOutbox(settlementId: number) {
    const lines = await this.prisma.settlement_lines.findMany({
      where: { settlement_id: BigInt(settlementId) },
      select: { id: true },
    });
    const lineIds = lines.map((l) => l.id);
    if (lineIds.length === 0) return [];
    return this.prisma.transfer_outbox.findMany({
      where: { settlement_line_id: { in: lineIds } },
      orderBy: { id: 'asc' },
    });
  }

  /** Preview the split without writing anything. Useful for UI. */
  preview(grossCents: number, profileKey: string, currency = 'EUR') {
    const gross   = Money.fromCents(grossCents, currency);
    const profile = SplitProfile.fromKey(profileKey);
    const result  = this.calc.calculate(gross, profile);
    return {
      gross_cents:   result.gross.cents,
      artist_cents:  result.artist.cents,
      fund_cents:    result.fund.cents,
      ops_cents:     result.ops.cents,
      profile_key:   result.profileKey,
      profile_version: result.profileVersion,
      artist_bps:    result.artistBps,
      fund_bps:      result.fundBps,
      ops_bps:       result.opsBps,
    };
  }
}
