import { Injectable, UnauthorizedException } from '@nestjs/common';
import { PassportStrategy } from '@nestjs/passport';
import { Strategy } from 'passport-custom';
import { Request } from 'express';

@Injectable()
export class JwtRefreshStrategy extends PassportStrategy(Strategy, 'jwt-refresh') {
  validate(req: Request): { refreshToken: string } {
    const token: string | undefined = req?.cookies?.refresh_token;
    if (!token) {
      throw new UnauthorizedException('Refresh token missing');
    }
    return { refreshToken: token };
  }
}
