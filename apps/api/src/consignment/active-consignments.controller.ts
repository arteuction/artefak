import { Controller, Get, Query, DefaultValuePipe, ParseIntPipe } from '@nestjs/common';
import { ApiTags, ApiOperation } from '@nestjs/swagger';
import { PrismaService } from '../prisma.service';

@ApiTags('active-consignments')
@Controller('active-consignments')
export class ActiveConsignmentsController {
  constructor(private readonly prisma: PrismaService) {}

  @Get()
  @ApiOperation({ summary: 'List currently active consignments (public)' })
  async list(
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    const skip = (page - 1) * perPage;
    const where = { status: 'active' as const };
    const [data, total] = await this.prisma.$transaction([
      this.prisma.consignments.findMany({
        where,
        select: {
          id: true, commission_bps: true, status: true, starts_at: true, ends_at: true, created_at: true,
          artworks: { select: { id: true, title: true, slug: true } },
          galleries: { select: { id: true, name: true, slug: true } },
        },
        orderBy: [{ created_at: 'desc' }],
        skip,
        take: perPage,
      }),
      this.prisma.consignments.count({ where }),
    ]);
    return { data, meta: { current_page: page, last_page: Math.max(1, Math.ceil(Number(total) / perPage)), per_page: perPage, total: Number(total) } };
  }
}
