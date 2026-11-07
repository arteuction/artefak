import { Module } from '@nestjs/common';
import { ScheduleModule } from '@nestjs/schedule';
import { PrismaService } from '../prisma.service';
import { SchedulerService } from './scheduler.service';

@Module({
  imports:   [ScheduleModule.forRoot()],
  providers: [PrismaService, SchedulerService],
  exports:   [SchedulerService],
})
export class SchedulerModule {}
