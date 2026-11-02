import { Module } from '@nestjs/common';
import { LibraryModule } from './library/library.module';
import { AuctionModule } from './auction/auction.module';
import { AuthModule } from './auth/auth.module';
import { ProfileModule } from './profile/profile.module';

@Module({
  imports: [AuthModule, LibraryModule, AuctionModule, ProfileModule],
})
export class AppModule {}
