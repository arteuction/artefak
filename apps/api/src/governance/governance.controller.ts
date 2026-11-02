import { Controller, Get, Post, Delete, Param, Body, Req, UseGuards, HttpCode, ParseIntPipe } from '@nestjs/common';
import { ApiTags, ApiBearerAuth, ApiOperation } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { CurrentUser } from '../auth/current-user.decorator';
import { AuthenticatedUser } from '../auth/auth.types';
import { GovernanceService } from './governance.service';

@ApiTags('governance')
@Controller('governance')
export class GovernanceController {
  constructor(private readonly governanceService: GovernanceService) {}

  @Get('policies')
  @ApiOperation({ summary: 'List active governance policies' })
  listPolicies() {
    return this.governanceService.listPolicies();
  }

  @Post('policies/:governancePolicy/consent')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Give consent to a governance policy' })
  giveConsent(
    @CurrentUser() user: AuthenticatedUser,
    @Param('governancePolicy', ParseIntPipe) policyId: number,
    @Body() _body: Record<string, unknown>,
    @Req() req: { headers: Record<string, string> },
  ) {
    return this.governanceService.giveConsent(user.id, policyId, req.headers['x-forwarded-for'], req.headers['user-agent']);
  }

  @Delete('policies/:governancePolicy/consent')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Withdraw consent from a governance policy' })
  withdrawConsent(
    @CurrentUser() user: AuthenticatedUser,
    @Param('governancePolicy', ParseIntPipe) policyId: number,
  ) {
    return this.governanceService.withdrawConsent(user.id, policyId);
  }
}
