import { Module } from '@nestjs/common';
import { ProfileController } from './profile.controller';
import { MyController } from './my.controller';
import { ProfileService } from './profile.service';
import { GovernanceService } from '../governance/governance.service';
import { PrismaService } from '../prisma.service';

@Module({
  controllers: [ProfileController, MyController],
  providers: [ProfileService, GovernanceService, PrismaService],
})
export class ProfileModule {}
