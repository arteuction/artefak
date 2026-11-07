import { Module } from '@nestjs/common';
import { ConsignmentController } from './consignment.controller';
import { ActiveConsignmentsController } from './active-consignments.controller';
import { ConsignmentService } from './consignment.service';
import { PrismaService } from '../prisma.service';

@Module({
  controllers: [ConsignmentController, ActiveConsignmentsController],
  providers: [ConsignmentService, PrismaService],
  exports: [ConsignmentService],
})
export class ConsignmentModule {}
