import {
  Controller,
  Post,
  Body,
  Res,
  Req,
  UseGuards,
  HttpCode,
  HttpStatus,
  BadRequestException,
} from '@nestjs/common';
import { Response, Request } from 'express';
import { AuthService } from './auth.service';
import { JwtAuthGuard } from './jwt-auth.guard';
import { JwtRefreshGuard } from './jwt-refresh.guard';
import { CurrentUser } from './current-user.decorator';
import { AuthenticatedUser } from './auth.types';

const REFRESH_COOKIE_OPTIONS = {
  httpOnly: true,
  secure: process.env.NODE_ENV === 'production',
  sameSite: 'strict' as const,
  maxAge: 30 * 24 * 60 * 60 * 1000, // 30 days in ms
  path: '/api/v1/auth',
};

@Controller('api/v1/auth')
export class AuthController {
  constructor(private readonly authService: AuthService) {}

  // â”€â”€ POST /api/v1/auth/register â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  @Post('register')
  @HttpCode(HttpStatus.CREATED)
  async register(
    @Body() body: { name?: string; email?: string; password?: string },
    @Res({ passthrough: true }) res: Response,
    @Req() req: Request,
  ) {
    const { name, email, password } = body;
    if (!name || !email || !password) {
      throw new BadRequestException('name, email and password are required');
    }
    if (password.length < 8) {
      throw new BadRequestException('password must be at least 8 characters');
    }

    const user = await this.authService.register(name, email, password);
    const { accessToken, refreshToken } = await this.authService.issueTokenPair(user);

    await this.authService.logAuthEvent(user.id, 'register', req.ip);

    res.cookie('refresh_token', refreshToken, REFRESH_COOKIE_OPTIONS);

    return {
      user: { id: user.id, email: user.email, role: user.role },
      access_token: accessToken,
    };
  }

  // â”€â”€ POST /api/v1/auth/login â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  @Post('login')
  @HttpCode(HttpStatus.OK)
  async login(
    @Body() body: { email?: string; password?: string },
    @Res({ passthrough: true }) res: Response,
    @Req() req: Request,
  ) {
    const { email, password } = body;
    if (!email || !password) {
      throw new BadRequestException('email and password are required');
    }

    const user = await this.authService.validateCredentials(email, password);
    const { accessToken, refreshToken } = await this.authService.issueTokenPair(user);

    await this.authService.logAuthEvent(user.id, 'login', req.ip);

    res.cookie('refresh_token', refreshToken, REFRESH_COOKIE_OPTIONS);

    return {
      user: { id: user.id, email: user.email, role: user.role },
      access_token: accessToken,
    };
  }

  // â”€â”€ POST /api/v1/auth/refresh â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  @Post('refresh')
  @HttpCode(HttpStatus.OK)
  @UseGuards(JwtRefreshGuard)
  async refresh(
    @Req() req: Request & { user: { userId: number; refreshToken: string } },
    @Res({ passthrough: true }) res: Response,
  ) {
    const { userId, refreshToken: incomingToken } = req.user;
    const { accessToken, refreshToken: newRefresh } =
      await this.authService.rotateRefreshToken(userId, incomingToken);

    res.cookie('refresh_token', newRefresh, REFRESH_COOKIE_OPTIONS);

    return { access_token: accessToken };
  }

  // â”€â”€ POST /api/v1/auth/logout â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  @Post('logout')
  @HttpCode(HttpStatus.NO_CONTENT)
  @UseGuards(JwtAuthGuard)
  async logout(
    @CurrentUser() user: AuthenticatedUser,
    @Req() req: Request,
    @Res({ passthrough: true }) res: Response,
  ) {
    const refreshToken: string | undefined = req.cookies?.refresh_token;
    if (refreshToken) {
      await this.authService.revokeToken(refreshToken);
    }

    await this.authService.logAuthEvent(user.id, 'logout', req.ip);

    res.clearCookie('refresh_token', { path: '/api/v1/auth' });
  }

  // â”€â”€ POST /api/v1/auth/logout-all â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  @Post('logout-all')
  @HttpCode(HttpStatus.NO_CONTENT)
  @UseGuards(JwtAuthGuard)
  async logoutAll(
    @CurrentUser() user: AuthenticatedUser,
    @Res({ passthrough: true }) res: Response,
  ) {
    await this.authService.revokeAllForUser(user.id);
    res.clearCookie('refresh_token', { path: '/api/v1/auth' });
  }
}

