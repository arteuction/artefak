import { Module } from '@nestjs/common';
import { LibraryModule } from './library/library.module';
import { AuctionModule } from './auction/auction.module';

@Module({
  imports: [LibraryModule, AuctionModule],
})
export class AppModule {}
