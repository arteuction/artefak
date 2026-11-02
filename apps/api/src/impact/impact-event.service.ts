import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

@Injectable()
export class ImpactEventService {
  constructor(private readonly prisma: PrismaService) {}

  async listEvents(page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const [data, total] = await this.prisma.$transaction([
      this.prisma.impact_events.findMany({
        select: {
          id: true, sdg_number: true, metric: true, magnitude: true,
          currency: true, type: true, created_at: true,
        },
        orderBy: [{ created_at: 'desc' }],
        skip,
        take: perPage,
      }),
      this.prisma.impact_events.count(),
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

  async getEvent(id: number) {
    const event = await this.prisma.impact_events.findFirst({
      where: { id: BigInt(id) },
    });
    if (!event) throw new NotFoundException(`ImpactEvent ${id} not found`);
    return event;
  }
}
