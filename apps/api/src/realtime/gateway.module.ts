import { Module } from '@nestjs/common';
import { BidGateway } from './bid.gateway';

@Module({
  providers: [BidGateway],
  exports: [BidGateway],
})
export class GatewayModule {}
