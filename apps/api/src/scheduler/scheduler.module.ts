import { Module } from '@nestjs/common';
import { ScheduleModule } from '@nestjs/schedule';
import { PrismaService } from '../prisma.service';
import { SchedulerService } from './scheduler.service';

// Without ScheduleModule the @Cron jobs never register. SCHEDULER_ENABLED=false keeps a NestJS
// instance from running crons against a shared DB while Laravel still owns those domains.
export const schedulerImports = (env: NodeJS.ProcessEnv = process.env) =>
  env.SCHEDULER_ENABLED === 'false' ? [] : [ScheduleModule.forRoot()];

@Module({
  imports:   schedulerImports(),
  providers: [PrismaService, SchedulerService],
  exports:   [SchedulerService],
})
export class SchedulerModule {}
