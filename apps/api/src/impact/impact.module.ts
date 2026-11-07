import { Module } from '@nestjs/common';
import { ImpactEventController } from './impact-event.controller';
import { ImpactEventService } from './impact-event.service';
import { ImpactProjectController } from './impact-project.controller';
import { ImpactProjectService } from './impact-project.service';
import { PrismaService } from '../prisma.service';

@Module({
  controllers: [ImpactEventController, ImpactProjectController],
  providers: [ImpactEventService, ImpactProjectService, PrismaService],
  exports: [ImpactEventService, ImpactProjectService],
})
export class ImpactModule {}
