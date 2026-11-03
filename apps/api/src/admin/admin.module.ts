import { Module } from '@nestjs/common';
import { AdminController } from './admin.controller';
import { AdminService } from './admin.service';
import { OpsController } from './ops.controller';
import { OpsService } from './ops.service';
import { EvidenceController, ApiClientController, PartnerController, DomainEventController, SdgClaimImpactController } from './misc.controller';
import { ArtifactLegacyController, ArtmetroRouteLegacyController } from './artmetro-legacy.controller';
import { PrismaService } from '../prisma.service';

@Module({
  controllers: [AdminController, OpsController, EvidenceController, ApiClientController, PartnerController, DomainEventController, SdgClaimImpactController, ArtifactLegacyController, ArtmetroRouteLegacyController],
  providers: [AdminService, OpsService, PrismaService],
})
export class AdminModule {}
