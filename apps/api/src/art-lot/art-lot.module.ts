import { Module } from '@nestjs/common';
import { Controller, Get, Param } from '@nestjs/common';
import { ApiOperation, ApiTags } from '@nestjs/swagger';
import { ArtLotController } from './art-lot.controller';
import { ArtLotService } from './art-lot.service';
import { PrismaService } from '../prisma.service';

@ApiTags('lots')
@Controller('lots')
class LotsController {
  @Get(':artLot/qr')
  @ApiOperation({ summary: 'Get QR code for lot' })
  qr(@Param('artLot') _artLot: string) { return { qr_url: 'https://stub.example.com/qr' }; }
}

@Module({
  controllers: [ArtLotController, LotsController],
  providers: [ArtLotService, PrismaService],
  exports: [ArtLotService],
})
export class ArtLotModule {}
