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
}
