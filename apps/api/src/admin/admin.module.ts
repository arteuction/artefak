import { Module } from '@nestjs/common';
import { AdminController } from './admin.controller';
import { OpsController } from './ops.controller';
import { EvidenceController, ApiClientController, PartnerController, DomainEventController, SdgClaimImpactController, WatchlistController } from './misc.controller';
import { ArtifactLegacyController, ArtmetroRouteLegacyController } from './artmetro-legacy.controller';

@Module({
  controllers: [AdminController, OpsController, EvidenceController, ApiClientController, PartnerController, DomainEventController, SdgClaimImpactController, WatchlistController, ArtifactLegacyController, ArtmetroRouteLegacyController],
})
export class AdminModule {}
