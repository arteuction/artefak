import { Module } from '@nestjs/common';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { ThrottlerModule } from '@nestjs/throttler';
import { AuthService } from './auth.service';
import { AuthController } from './auth.controller';
import { AuthorizationService } from './authorization.service';
import { JwtStrategy } from './jwt.strategy';
import { JwtRefreshStrategy } from './jwt-refresh.strategy';
import { RolesGuard } from './roles.guard';
import { PrismaService } from '../prisma.service';

@Module({
  imports: [
    PassportModule.register({ defaultStrategy: 'jwt' }),
    JwtModule.register({
      secret: process.env.JWT_SECRET ?? 'change-me-in-production',
      signOptions: { expiresIn: '15m' },
    }),
    ThrottlerModule.forRoot([
      {
        // login/register: max 10 requests per minute per IP
        name: 'auth',
        ttl: 60_000,
        limit: 10,
      },
    ]),
  ],
  controllers: [AuthController],
  providers: [
    AuthService,
    AuthorizationService,
    JwtStrategy,
    JwtRefreshStrategy,
    RolesGuard,
    PrismaService,
  ],
  exports: [AuthService, AuthorizationService, JwtStrategy, JwtRefreshStrategy, RolesGuard],
})
export class AuthModule {}

