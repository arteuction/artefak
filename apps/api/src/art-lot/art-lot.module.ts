import { Module } from '@nestjs/common';
import { ArtLotController } from './art-lot.controller';
import { ArtLotService } from './art-lot.service';
import { PrismaService } from '../prisma.service';

@Module({
  controllers: [ArtLotController],
  providers: [ArtLotService, PrismaService],
  exports: [ArtLotService],
})
export class ArtLotModule {}
