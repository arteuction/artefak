import { Module } from '@nestjs/common';
import { ArtworkController } from './artwork.controller';
import { ArtworkService } from './artwork.service';
import { PrismaService } from '../prisma.service';

@Module({
  controllers: [ArtworkController],
  providers: [ArtworkService, PrismaService],
  exports: [ArtworkService],
})
export class ArtworkModule {}
