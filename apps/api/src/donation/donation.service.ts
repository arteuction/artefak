import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

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
}
