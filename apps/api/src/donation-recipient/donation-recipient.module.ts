import { Module } from '@nestjs/common';
import { DonationRecipientController } from './donation-recipient.controller';
import { DonationRecipientService } from './donation-recipient.service';
import { PrismaService } from '../prisma.service';

@Module({
  controllers: [DonationRecipientController],
  providers: [DonationRecipientService, PrismaService],
  exports: [DonationRecipientService],
})
export class DonationRecipientModule {}
