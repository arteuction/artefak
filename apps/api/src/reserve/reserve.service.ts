import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

@Injectable()
export class ReserveService {
  constructor(private readonly prisma: PrismaService) {}

  async getReserve(reserveId: number) {
    const reserve = await this.prisma.reserves.findFirst({
      where: { id: BigInt(reserveId) },
    });
    if (!reserve) throw new NotFoundException(`Reserve ${reserveId} not found`);
    return reserve;
  }
}
