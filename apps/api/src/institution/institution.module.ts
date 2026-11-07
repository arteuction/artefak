import { Module } from '@nestjs/common';
import { InstitutionController } from './institution.controller';
import { InstitutionService } from './institution.service';
import { PrismaService } from '../prisma.service';

@Module({
  controllers: [InstitutionController],
  providers: [InstitutionService, PrismaService],
})
export class InstitutionModule {}
