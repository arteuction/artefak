import { Controller, Get, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { CurrentUser } from '../auth/current-user.decorator';
import { AuthenticatedUser } from '../auth/auth.types';

@ApiTags('profile')
@ApiBearerAuth()
@Controller('me')
export class ProfileController {
  /**
   * GET /api/v1/me — mirrors Laravel UserController#me (auth:sanctum protected).
   * Returns the authenticated user's profile fields.
   */
  @Get()
  @UseGuards(JwtAuthGuard)
  @ApiOperation({ summary: 'Get authenticated user profile' })
  getProfile(@CurrentUser() user: AuthenticatedUser) {
    return {
      id: user.id,
      email: user.email,
      role: user.role,
      email_verified_at: user.emailVerifiedAt,
    };
  }
}
