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

  async getEventEvidence(eventId: number) {
    const event = await this.getEvent(eventId);
    return this.prisma.impact_evidence.findMany({
      where: { impact_event_id: event.id, deleted_at: null },
      select: { id: true, type: true, url: true, description: true, created_at: true },
      orderBy: [{ created_at: 'desc' }],
    });
  }

  async createEventEvidence(eventId: number, uploadedBy: number, dto: { type: string; url: string; description?: string }) {
    const event = await this.getEvent(eventId);
    return this.prisma.impact_evidence.create({
      data: {
        impact_event_id: event.id,
        uploaded_by:     BigInt(uploadedBy),
        type:            dto.type as any,
        url:             dto.url,
        description:     dto.description ?? null,
      },
      select: { id: true, type: true, url: true, description: true, created_at: true },
    });
  }
}
