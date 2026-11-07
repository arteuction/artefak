import { Module } from '@nestjs/common';
import { OwnershipTransferController } from './ownership-transfer.controller';
import { OwnershipTransferService } from './ownership-transfer.service';
import { PrismaService } from '../prisma.service';

@Module({
  controllers: [OwnershipTransferController],
  providers: [OwnershipTransferService, PrismaService],
  exports: [OwnershipTransferService],
})
export class OwnershipTransferModule {}
