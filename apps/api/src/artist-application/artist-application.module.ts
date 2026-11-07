import { Module } from '@nestjs/common';
import { ArtistApplicationController } from './artist-application.controller';
import { ArtistApplicationService } from './artist-application.service';
import { PrismaService } from '../prisma.service';

@Module({
  controllers: [ArtistApplicationController],
  providers: [ArtistApplicationService, PrismaService],
})
export class ArtistApplicationModule {}
