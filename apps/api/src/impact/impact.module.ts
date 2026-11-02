import { Module } from '@nestjs/common';
import { ImpactEventController } from './impact-event.controller';
import { ImpactEventService } from './impact-event.service';
import { PrismaService } from '../prisma.service';

@Module({
  controllers: [ImpactEventController],
  providers: [ImpactEventService, PrismaService],
  exports: [ImpactEventService],
})
export class ImpactModule {}
