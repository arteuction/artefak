import { Module } from '@nestjs/common';
import { S3Module } from './s3/s3.module';
import { StripeModule } from './stripe/stripe.module';
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
import { OwnershipTransferModule } from './ownership-transfer/ownership-transfer.module';
import { ReserveModule } from './reserve/reserve.module';
import { SearchModule } from './search/search.module';
import { InstitutionModule } from './institution/institution.module';
import { ArtistApplicationModule } from './artist-application/artist-application.module';
import { GovernanceModule } from './governance/governance.module';
import { BookCategoryModule } from './book-category/book-category.module';
import { PayoutModule } from './payout/payout.module';
import { SettlementModule } from './settlement/settlement.module';
import { SchedulerModule } from './scheduler/scheduler.module';
import { AdminModule } from './admin/admin.module';
import { HealthController } from './health.controller';
import { AuctionService } from './auction/auction.service';
import { PrismaService } from './prisma.service';

@Module({
  imports: [S3Module, StripeModule, AuthModule, LibraryModule, AuctionModule, ProfileModule, ArtworkModule, GalleryModule, ArtistModule, CollectionModule, SellNowModule, ArtLotModule, VenueModule, ExhibitionModule, DisputeModule, DonationModule, ConsignmentModule, ImpactModule, DonationRecipientModule, OwnershipTransferModule, ReserveModule, SearchModule, InstitutionModule, ArtistApplicationModule, GovernanceModule, BookCategoryModule, PayoutModule, AdminModule, SettlementModule, SchedulerModule],
  controllers: [HealthController],
  providers: [AuctionService, PrismaService],
})
export class AppModule {}
