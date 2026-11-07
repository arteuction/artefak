import { Module } from '@nestjs/common';
import { GalleryController } from './gallery.controller';
import { GalleryService } from './gallery.service';
import { PrismaService } from '../prisma.service';

@Module({
  controllers: [GalleryController],
  providers: [GalleryService, PrismaService],
  exports: [GalleryService],
})
export class GalleryModule {}
