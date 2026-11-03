/**
 * Admin stub controller — covers all /admin/* routes as stubs returning empty data.
 * Full implementations are deferred until auth/authorization parity is demonstrated.
 */
import { Controller, Get, Post, Patch, Delete, Param, Body, UseGuards, HttpCode } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { RolesGuard } from '../auth/roles.guard';
import { Roles } from '../auth/roles.decorator';

const empty = () => ({ data: [], meta: { total: 0, current_page: 1, last_page: 1, per_page: 20 } });
const stub = (extra?: object) => ({ status: 'ok', ...extra });

@ApiTags('admin')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, RolesGuard)
@Roles('admin', 'operator')
@Controller('admin')
export class AdminController {
  // Audit log
  @Get('audit-log') @ApiOperation({ summary: 'List admin audit log' }) auditLog() { return empty(); }
  @Get('audit-log/:id') @ApiOperation({ summary: 'Get audit log entry' }) auditLogEntry() { return stub(); }
  // Settlements
  @Get('settlements') @ApiOperation({ summary: 'List settlements' }) settlements() { return empty(); }
  @Get('settlements/:id') @ApiOperation({ summary: 'Get settlement' }) settlement() { return stub(); }
  @Get('settlements/:id/statement') @ApiOperation({ summary: 'Get settlement statement' }) statement() { return stub(); }
  // Reconciliation
  @Get('reconciliation-runs') @ApiOperation({ summary: 'List reconciliation runs' }) reconRuns() { return empty(); }
  @Get('reconciliation-runs/:id') @ApiOperation({ summary: 'Get reconciliation run' }) reconRun() { return stub(); }
  @Post('reconciliation-runs') @HttpCode(201) @ApiOperation({ summary: 'Create reconciliation run' }) createReconRun() { return stub(); }
  @Get('reconciliation-mismatches') @ApiOperation({ summary: 'List mismatches' }) mismatches() { return empty(); }
  @Get('reconciliation-mismatches/:id') @ApiOperation({ summary: 'Get mismatch' }) mismatch() { return stub(); }
  @Patch('reconciliation-mismatches/:id') @ApiOperation({ summary: 'Update mismatch' }) updateMismatch() { return stub(); }
  // ArtMetro
  @Post('artmetro/routes') @HttpCode(201) @ApiOperation({ summary: 'Create artmetro route' }) createRoute() { return stub(); }
  @Patch('artmetro/routes/:route') @ApiOperation({ summary: 'Update artmetro route' }) updateRoute() { return stub(); }
  @Patch('artmetro/routes/:route/publish') @ApiOperation({ summary: 'Toggle artmetro route publish' }) toggleRoute() { return stub(); }
  @Post('artmetro/routes/:route/stops') @HttpCode(201) @ApiOperation({ summary: 'Add stop to artmetro route' }) addStop() { return stub(); }
  @Delete('artmetro/routes/:route/stops/:stop') @HttpCode(200) @ApiOperation({ summary: 'Remove stop from artmetro route' }) removeStop() { return stub(); }
  // Users
  @Get('users') @ApiOperation({ summary: 'List users' }) users() { return empty(); }
  @Get('users/:user') @ApiOperation({ summary: 'Get user' }) user() { return stub(); }
  @Patch('users/:user') @ApiOperation({ summary: 'Update user' }) updateUser() { return stub(); }
  // Ledger & refunds
  @Get('ledger') @ApiOperation({ summary: 'List ledger entries' }) ledger() { return empty(); }
  @Get('refunds') @ApiOperation({ summary: 'List refunds' }) refunds() { return empty(); }
  // Split profiles
  @Get('split-profiles') @ApiOperation({ summary: 'List split profiles' }) splitProfiles() { return empty(); }
  @Post('split-profiles') @HttpCode(201) @ApiOperation({ summary: 'Create split profile' }) createSplitProfile() { return stub(); }
  @Patch('split-profiles/:id/deprecate') @ApiOperation({ summary: 'Deprecate split profile' }) deprecateSplitProfile() { return stub(); }
  // Settlement lines
  @Get('settlement-lines') @ApiOperation({ summary: 'List settlement lines' }) settlementLines() { return empty(); }
  @Post('settlement-lines/:id/disburse') @HttpCode(200) @ApiOperation({ summary: 'Disburse settlement line' }) disburse() { return stub(); }
  // Books
  @Post('books') @HttpCode(201) @ApiOperation({ summary: 'Create book (admin)' }) createBook() { return stub(); }
  @Patch('books/:book') @ApiOperation({ summary: 'Update book (admin)' }) updateBook() { return stub(); }
  // Book categories
  @Post('book-categories') @HttpCode(201) @ApiOperation({ summary: 'Create book category (admin)' }) createBookCategory() { return stub(); }
  @Patch('book-categories/:category') @ApiOperation({ summary: 'Update book category' }) updateBookCategory() { return stub(); }
  @Delete('book-categories/:category') @HttpCode(200) @ApiOperation({ summary: 'Delete book category' }) deleteBookCategory() { return stub(); }
  // Book reviews moderation
  @Get('books/:book/reviews/pending') @ApiOperation({ summary: 'List pending book reviews' }) pendingReviews() { return empty(); }
  @Patch('books/:book/reviews/:review/approve') @ApiOperation({ summary: 'Approve book review' }) approveReview() { return stub(); }
  @Delete('books/:book/reviews/:review') @HttpCode(200) @ApiOperation({ summary: 'Delete book review' }) deleteReview() { return stub(); }
  // Rulesets
  @Get('rulesets') @ApiOperation({ summary: 'List rulesets' }) rulesets() { return empty(); }
  @Get('rulesets/:ruleset') @ApiOperation({ summary: 'Get ruleset' }) ruleset() { return stub(); }
  @Post('rulesets') @HttpCode(201) @ApiOperation({ summary: 'Create ruleset' }) createRuleset() { return stub(); }
  @Patch('rulesets/:ruleset') @ApiOperation({ summary: 'Update ruleset' }) updateRuleset() { return stub(); }
  // Transfer outbox
  @Get('transfer-outbox') @ApiOperation({ summary: 'List transfer outbox' }) transferOutbox() { return empty(); }
  @Get('transfer-outbox/:id') @ApiOperation({ summary: 'Get transfer outbox item' }) transferOutboxItem() { return stub(); }
  // Connected payouts
  @Get('connected-payouts') @ApiOperation({ summary: 'List connected payouts' }) connectedPayouts() { return empty(); }
  @Get('connected-payouts/:id') @ApiOperation({ summary: 'Get connected payout' }) connectedPayout() { return stub(); }
  // Auctions admin
  @Post('auctions') @HttpCode(201) @ApiOperation({ summary: 'Create auction (admin)' }) createAuction() { return stub(); }
  @Patch('auctions/:auction') @ApiOperation({ summary: 'Update auction (admin)' }) updateAuction() { return stub(); }
  @Post('auctions/:auction/items') @HttpCode(201) @ApiOperation({ summary: 'Add auction item' }) addAuctionItem() { return stub(); }
  @Delete('auctions/:auction/items/:item') @HttpCode(200) @ApiOperation({ summary: 'Remove auction item' }) removeAuctionItem() { return stub(); }
  @Post('auctions/:auction/publish') @HttpCode(200) @ApiOperation({ summary: 'Publish auction' }) publishAuction() { return stub(); }
  @Post('auctions/:auction/open') @HttpCode(200) @ApiOperation({ summary: 'Open auction' }) openAuction() { return stub(); }
  @Post('auctions/:auction/close') @HttpCode(200) @ApiOperation({ summary: 'Close auction' }) closeAuction() { return stub(); }
}
