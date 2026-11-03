import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

@Injectable()
export class DonationRecipientService {
  constructor(private readonly prisma: PrismaService) {}

  async listRecipients(page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const where = { status: 'active' as const };

    const [data, total] = await this.prisma.$transaction([
      this.prisma.donation_recipients.findMany({
        where,
        select: {
          id: true, name: true, legal_type: true, eligibility_basis: true,
          deduction_bps: true, status: true, created_at: true,
        },
        orderBy: [{ name: 'asc' }],
        skip,
        take: perPage,
      }),
      this.prisma.donation_recipients.count({ where }),
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

  async getRecipient(id: number) {
    const recipient = await this.prisma.donation_recipients.findFirst({
      where: { id: BigInt(id) },
    });
    if (!recipient) throw new NotFoundException(`DonationRecipient ${id} not found`);
    return recipient;
  }

  async createRecipient(dto: { name: string; legal_type?: string; eligibility_basis?: string; deduction_bps?: number }) {
    return this.prisma.donation_recipients.create({
      data: {
        name: dto.name,
        legal_type: (dto.legal_type ?? 'ngo') as any,
        eligibility_basis: (dto.eligibility_basis ?? 'art31') as any,
        deduction_bps: dto.deduction_bps ?? 0,
        status: 'active' as any,
      } as any,
      select: { id: true, name: true, legal_type: true, eligibility_basis: true, deduction_bps: true, status: true, created_at: true },
    });
  }

  async updateRecipient(id: number, dto: { name?: string; legal_type?: string; deduction_bps?: number; status?: string }) {
    await this.getRecipient(id);
    return this.prisma.donation_recipients.update({
      where: { id: BigInt(id) },
      data: {
        ...(dto.name !== undefined ? { name: dto.name } : {}),
        ...(dto.legal_type !== undefined ? { legal_type: dto.legal_type as any } : {}),
        ...(dto.deduction_bps !== undefined ? { deduction_bps: dto.deduction_bps } : {}),
        ...(dto.status !== undefined ? { status: dto.status as any } : {}),
      },
      select: { id: true, name: true, status: true, updated_at: true },
    });
  }
}
