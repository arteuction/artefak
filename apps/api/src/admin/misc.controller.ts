/**
 * Stub controller for miscellaneous routes not covered by other modules.
 * Covers: /evidence, /api-clients, /partners, /domain-events, /sdg-claims/:claim/impact-events
 */
import { Controller, Get, Post, Delete, Param, Body, UseGuards, HttpCode } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';

const empty = () => ({ data: [] });
const stub = (extra?: object) => ({ status: 'ok', ...extra });

@ApiTags('evidence')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard)
@Controller('evidence')
export class EvidenceController {
  @Get() @ApiOperation({ summary: 'List evidence (admin)' }) index() { return empty(); }
  @Get(':evidence') @ApiOperation({ summary: 'Get evidence' }) show() { return stub(); }
  @Post(':evidence/verify') @HttpCode(200) @ApiOperation({ summary: 'Verify evidence' }) verify() { return stub({ verified: true }); }
}

@ApiTags('api-clients')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard)
@Controller('api-clients')
export class ApiClientController {
  @Get() @ApiOperation({ summary: 'List API clients' }) index() { return empty(); }
  @Post() @HttpCode(201) @ApiOperation({ summary: 'Create API client' }) store() { return stub(); }
  @Delete(':apiClient') @HttpCode(200) @ApiOperation({ summary: 'Delete API client' }) destroy() { return stub({ deleted: true }); }
}

@ApiTags('partners')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard)
@Controller('partners')
export class PartnerController {
  @Post('onboard') @HttpCode(201) @ApiOperation({ summary: 'Onboard partner' }) onboard(@Body() _body: Record<string, unknown>) { return stub({ onboarded: true }); }
}

@ApiTags('domain-events')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard)
@Controller('domain-events')
export class DomainEventController {
  @Get() @ApiOperation({ summary: 'List domain events (operator)' }) index() { return empty(); }
}

@ApiTags('sdg-claims')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard)
@Controller('sdg-claims')
export class SdgClaimImpactController {
  @Post(':claim/impact-events') @HttpCode(201) @ApiOperation({ summary: 'Record impact event against SDG claim' })
  store(@Param('claim') _claim: string, @Body() _body: Record<string, unknown>) { return stub({ recorded: true }); }
}

@ApiTags('watchlist')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard)
@Controller('watchlist')
export class WatchlistController {
  @Get() @ApiOperation({ summary: 'List watchlist items' }) index() { return empty(); }
  @Post() @HttpCode(201) @ApiOperation({ summary: 'Add to watchlist' }) store(@Body() _body: Record<string, unknown>) { return stub(); }
  @Delete(':type/:id') @HttpCode(200) @ApiOperation({ summary: 'Remove from watchlist' }) destroy() { return stub({ deleted: true }); }
}
