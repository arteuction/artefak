import { Module } from '@nestjs/common';
import { LibraryModule } from './library/library.module';
import { AuctionModule } from './auction/auction.module';
import { AuthModule } from './auth/auth.module';
import { ProfileModule } from './profile/profile.module';
import { ArtworkModule } from './artwork/artwork.module';
import { GalleryModule } from './gallery/gallery.module';
import { ArtistModule } from './artist/artist.module';
import { CollectionModule } from './collection/collection.module';
import { SellNowModule } from './sell-now/sell-now.module';
import { ArtLotModule } from './art-lot/art-lot.module';
import { VenueModule } from './venue/venue.module';
import { ExhibitionModule } from './exhibition/exhibition.module';
import { DisputeModule } from './dispute/dispute.module';
import { DonationModule } from './donation/donation.module';
import { ConsignmentModule } from './consignment/consignment.module';
import { ImpactModule } from './impact/impact.module';
import { DonationRecipientModule } from './donation-recipient/donation-recipient.module';
import { HealthController } from './health.controller';
import { AuctionService } from './auction/auction.service';
import { PrismaService } from './prisma.service';

@Module({
  imports: [AuthModule, LibraryModule, AuctionModule, ProfileModule, ArtworkModule, GalleryModule, ArtistModule, CollectionModule, SellNowModule, ArtLotModule, VenueModule, ExhibitionModule, DisputeModule, DonationModule, ConsignmentModule, ImpactModule, DonationRecipientModule],
  controllers: [HealthController],
  providers: [AuctionService, PrismaService],
})
export class AppModule {}
