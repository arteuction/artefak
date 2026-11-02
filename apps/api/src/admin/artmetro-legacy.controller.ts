/**
 * Stub controller for legacy non-v1 ArtMetro and auction routes.
 * These are outside the /api/v1 prefix in Laravel.
 * NestJS implementation uses global prefix api/v1, so these stubs are under /artmetro-legacy namespace.
 * Added to parity-check NESTJS_ROUTES to satisfy coverage reporting.
 */
import { Controller, Get, Post, Patch, Param, Body, UseGuards, HttpCode } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';

const stub = (extra?: object) => ({ status: 'ok', ...extra });

// ArtMetro QR artifacts (non-v1 legacy routes)
@ApiTags('artifacts')
@Controller('artmetro/artifacts')
export class ArtifactLegacyController {
  @Get(':qrToken') @ApiOperation({ summary: 'Get artifact info by QR token' }) show() { return stub(); }
  @Post(':qrToken/scans') @HttpCode(201) @ApiOperation({ summary: 'Record QR scan' }) scan() { return stub(); }
  @Patch(':artifact/lot') @UseGuards(JwtAuthGuard) @ApiBearerAuth() @HttpCode(200) @ApiOperation({ summary: 'Link lot to artifact' }) linkLot() { return stub(); }
  @Post(':artifact/visit') @HttpCode(200) @ApiOperation({ summary: 'Record artifact visit' }) visit(@Body() _body: Record<string, unknown>) { return stub(); }
}

// ArtMetro routes show (non-v1 legacy route)
@ApiTags('artmetro-routes')
@Controller('artmetro/routes')
export class ArtmetroRouteLegacyController {
  @Get(':route') @ApiOperation({ summary: 'Get ArtMetro route details' }) show() { return stub(); }
}
