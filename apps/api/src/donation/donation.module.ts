import { Module } from '@nestjs/common';
import { DonationController } from './donation.controller';
import { DonationService } from './donation.service';
import { PrismaService } from '../prisma.service';

@Module({
  controllers: [DonationController],
  providers: [DonationService, PrismaService],
  exports: [DonationService],
})
export class DonationModule {}
