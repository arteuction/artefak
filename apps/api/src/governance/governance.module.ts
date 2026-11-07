import { Module } from '@nestjs/common';
import { GovernanceController } from './governance.controller';
import { GovernanceService } from './governance.service';
import { PrismaService } from '../prisma.service';

@Module({
  controllers: [GovernanceController],
  providers: [GovernanceService, PrismaService],
})
export class GovernanceModule {}
