import { Module } from '@nestjs/common';
import { ProfileController } from './profile.controller';
import { ProfileService } from './profile.service';
import { GovernanceService } from '../governance/governance.service';
import { PrismaService } from '../prisma.service';

@Module({
  controllers: [ProfileController],
  providers: [ProfileService, GovernanceService, PrismaService],
})
export class ProfileModule {}
