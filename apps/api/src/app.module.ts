import { Module } from '@nestjs/common';
import { LibraryModule } from './library/library.module';
import { AuctionModule } from './auction/auction.module';
import { AuthModule } from './auth/auth.module';
import { ProfileModule } from './profile/profile.module';
import { HealthController } from './health.controller';
import { AuctionService } from './auction/auction.service';
import { PrismaService } from './prisma.service';

@Module({
  imports: [AuthModule, LibraryModule, AuctionModule, ProfileModule],
  controllers: [HealthController],
  providers: [AuctionService, PrismaService],
})
export class AppModule {}
