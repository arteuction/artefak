import { Injectable, NotFoundException } from '@nestjs/common';
import { randomUUID } from 'crypto';
import { PrismaService } from '../prisma.service';

export interface CreateDonationDto {
  donation_recipient_id: number;
  donated_cents: number;
  currency?: string;
  eligibility_basis?: string;
  deduction_bps?: number;
  impact_project_id?: number;
  idempotency_key?: string;
}

@Injectable()
export class DonationService {
  constructor(private readonly prisma: PrismaService) {}

  async listDonations(userId: number, role: string, page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const where = role === 'admin' || role === 'operator'
      ? {}
      : { donor_id: BigInt(userId) };

    const [data, total] = await this.prisma.$transaction([
      this.prisma.donations.findMany({
        where,
        select: {
          id: true, donated_cents: true, currency: true,
          type: true, status: true, eligibility_basis: true,
          deduction_bps: true, created_at: true,
        },
        orderBy: [{ created_at: 'desc' }],
        skip,
        take: perPage,
      }),
      this.prisma.donations.count({ where }),
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

  async createDonation(userId: number, dto: CreateDonationDto) {
    return this.prisma.donations.create({
      data: {
        donor_id:              BigInt(userId),
        donation_recipient_id: BigInt(dto.donation_recipient_id),
        donated_cents:         BigInt(dto.donated_cents),
        currency:              dto.currency ?? 'EUR',
        eligibility_basis:     dto.eligibility_basis ?? 'voluntary',
        deduction_bps:         dto.deduction_bps ?? 0,
        max_deductible_cents:  BigInt(dto.donated_cents),
        ...(dto.impact_project_id ? { impact_project_id: BigInt(dto.impact_project_id) } : {}),
        idempotency_key:       dto.idempotency_key ?? randomUUID(),
        status:                'pending' as any,
      },
      select: {
        id: true, donated_cents: true, currency: true,
        type: true, status: true, eligibility_basis: true,
        deduction_bps: true, created_at: true,
      },
    });
  }

  async getDonation(donationId: number, userId: number, role: string) {
    const donation = await this.prisma.donations.findFirst({
      where: { id: BigInt(donationId) },
    });
    if (!donation) throw new NotFoundException(`Donation ${donationId} not found`);
    if (role !== 'admin' && role !== 'operator' && Number(donation.donor_id) !== userId) {
      throw new NotFoundException(`Donation ${donationId} not found`);
    }
    return donation;
  }

  async taxReceipt(userId: number, year: number) {
    const start = new Date(`${year}-01-01T00:00:00Z`);
    const end = new Date(`${year + 1}-01-01T00:00:00Z`);

    const rows = await this.prisma.donations.findMany({
      where: {
        donor_id: BigInt(userId),
        status: 'completed' as any,
        eligibility_basis: { not: 'voluntary' },
        created_at: { gte: start, lt: end },
      },
      select: {
        id: true, donated_cents: true, max_deductible_cents: true,
        deduction_bps: true, currency: true, eligibility_basis: true,
        donation_recipients: { select: { id: true, name: true } },
        created_at: true,
      },
      orderBy: [{ created_at: 'asc' }],
    });

    const total_donated_cents = rows.reduce((s, r) => s + Number(r.donated_cents), 0);
    const total_deductible_cents = rows.reduce((s, r) => s + Number(r.max_deductible_cents), 0);

    return {
      year,
      currency: rows[0]?.currency ?? 'EUR',
      total_donated_cents,
      total_deductible_cents,
      rows,
    };
  }
}
