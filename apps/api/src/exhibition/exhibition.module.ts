import { Module } from '@nestjs/common';
import { ExhibitionController } from './exhibition.controller';
import { ExhibitionService } from './exhibition.service';
import { PrismaService } from '../prisma.service';

@Module({
  controllers: [ExhibitionController],
  providers: [ExhibitionService, PrismaService],
  exports: [ExhibitionService],
})
export class ExhibitionModule {}
