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
import { HealthController } from './health.controller';
import { AuctionService } from './auction/auction.service';
import { PrismaService } from './prisma.service';

@Module({
  imports: [AuthModule, LibraryModule, AuctionModule, ProfileModule, ArtworkModule, GalleryModule, ArtistModule, CollectionModule, SellNowModule],
  controllers: [HealthController],
  providers: [AuctionService, PrismaService],
})
export class AppModule {}
