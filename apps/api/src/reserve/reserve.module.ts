import { Module } from '@nestjs/common';
import { ReserveController } from './reserve.controller';
import { ReserveService } from './reserve.service';
import { PrismaService } from '../prisma.service';

@Module({
  controllers: [ReserveController],
  providers: [ReserveService, PrismaService],
  exports: [ReserveService],
})
export class ReserveModule {}
