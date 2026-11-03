import { Controller, Get, Post, Patch, Delete, Param, Body, Query, UseGuards, HttpCode, ParseIntPipe, DefaultValuePipe } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { RolesGuard } from '../auth/roles.guard';
import { Roles } from '../auth/roles.decorator';
import { AdminService } from './admin.service';

const stub = (extra?: object) => ({ status: 'ok', ...extra });

@ApiTags('admin')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, RolesGuard)
@Roles('admin', 'operator')
@Controller('admin')
export class AdminController {
  constructor(private readonly adminService: AdminService) {}

  // ── Audit log ────────────────────────────────────────────────────────────────

  @Get('audit-log')
  @ApiOperation({ summary: 'List admin audit log' })
  auditLog(
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.adminService.listAuditLog(page, perPage);
  }

  @Get('audit-log/:id')
  @ApiOperation({ summary: 'Get audit log entry' })
  auditLogEntry() { return stub(); }

  // ── Settlements ──────────────────────────────────────────────────────────────

  @Get('settlements')
  @ApiOperation({ summary: 'List settlements' })
  settlements(
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.adminService.listSettlements(page, perPage);
  }

  @Get('settlements/:id')
  @ApiOperation({ summary: 'Get settlement' })
  settlement(@Param('id', ParseIntPipe) id: number) {
    return this.adminService.getSettlement(id);
  }

  @Get('settlements/:id/statement')
  @ApiOperation({ summary: 'Get settlement statement' })
  statement() { return stub(); }

  // ── Reconciliation (stubs — requires separate reconciliation engine) ──────────

  @Get('reconciliation-runs') @ApiOperation({ summary: 'List reconciliation runs' }) reconRuns() { return { data: [], meta: { total: 0 } }; }
  @Get('reconciliation-runs/:id') @ApiOperation({ summary: 'Get reconciliation run' }) reconRun() { return stub(); }
  @Post('reconciliation-runs') @HttpCode(201) @ApiOperation({ summary: 'Create reconciliation run' }) createReconRun() { return stub(); }
  @Get('reconciliation-mismatches') @ApiOperation({ summary: 'List mismatches' }) mismatches() { return { data: [], meta: { total: 0 } }; }
  @Get('reconciliation-mismatches/:id') @ApiOperation({ summary: 'Get mismatch' }) mismatch() { return stub(); }
  @Patch('reconciliation-mismatches/:id') @ApiOperation({ summary: 'Update mismatch' }) updateMismatch() { return stub(); }

  // ── ArtMetro (stubs) ──────────────────────────────────────────────────────────

  @Post('artmetro/routes') @HttpCode(201) @ApiOperation({ summary: 'Create artmetro route' }) createRoute() { return stub(); }
  @Patch('artmetro/routes/:route') @ApiOperation({ summary: 'Update artmetro route' }) updateRoute() { return stub(); }
  @Patch('artmetro/routes/:route/publish') @ApiOperation({ summary: 'Toggle artmetro route publish' }) toggleRoute() { return stub(); }
  @Post('artmetro/routes/:route/stops') @HttpCode(201) @ApiOperation({ summary: 'Add stop to artmetro route' }) addStop() { return stub(); }
  @Delete('artmetro/routes/:route/stops/:stop') @HttpCode(200) @ApiOperation({ summary: 'Remove stop from artmetro route' }) removeStop() { return stub(); }

  // ── Users ────────────────────────────────────────────────────────────────────

  @Get('users')
  @ApiOperation({ summary: 'List users' })
  users(
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.adminService.listUsers(page, perPage);
  }

  @Get('users/:user')
  @ApiOperation({ summary: 'Get user' })
  user(@Param('user', ParseIntPipe) userId: number) {
    return this.adminService.getUser(userId);
  }

  @Patch('users/:user')
  @ApiOperation({ summary: 'Update user' })
  updateUser(
    @Param('user', ParseIntPipe) userId: number,
    @Body() body: { role?: string; name?: string },
  ) {
    return this.adminService.updateUser(userId, body);
  }

  // ── Ledger & refunds ──────────────────────────────────────────────────────────

  @Get('ledger')
  @ApiOperation({ summary: 'List ledger entries' })
  ledger(
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.adminService.listLedger(page, perPage);
  }

  @Get('refunds')
  @ApiOperation({ summary: 'List refunds' })
  refunds(
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.adminService.listRefunds(page, perPage);
  }

  // ── Split profiles (stubs — complex ruleset logic) ───────────────────────────

  @Get('split-profiles') @ApiOperation({ summary: 'List split profiles' }) splitProfiles() { return { data: [], meta: { total: 0 } }; }
  @Post('split-profiles') @HttpCode(201) @ApiOperation({ summary: 'Create split profile' }) createSplitProfile() { return stub(); }
  @Patch('split-profiles/:id/deprecate') @ApiOperation({ summary: 'Deprecate split profile' }) deprecateSplitProfile() { return stub(); }

  // ── Settlement lines ──────────────────────────────────────────────────────────

  @Get('settlement-lines')
  @ApiOperation({ summary: 'List settlement lines' })
  settlementLines(
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.adminService.listSettlementLines(page, perPage);
  }

  @Post('settlement-lines/:id/disburse')
  @HttpCode(200)
  @ApiOperation({ summary: 'Disburse settlement line' })
  disburse(@Param('id', ParseIntPipe) id: number) {
    return this.adminService.disburseSettlementLine(id);
  }

  // ── Books / book categories (stubs) ──────────────────────────────────────────

  @Post('books') @HttpCode(201) @ApiOperation({ summary: 'Create book (admin)' }) createBook() { return stub(); }
  @Patch('books/:book') @ApiOperation({ summary: 'Update book (admin)' }) updateBook() { return stub(); }
  @Post('book-categories') @HttpCode(201) @ApiOperation({ summary: 'Create book category (admin)' }) createBookCategory() { return stub(); }
  @Patch('book-categories/:category') @ApiOperation({ summary: 'Update book category' }) updateBookCategory() { return stub(); }
  @Delete('book-categories/:category') @HttpCode(200) @ApiOperation({ summary: 'Delete book category' }) deleteBookCategory() { return stub(); }
  @Get('books/:book/reviews/pending') @ApiOperation({ summary: 'List pending book reviews' }) pendingReviews() { return { data: [], meta: { total: 0 } }; }
  @Patch('books/:book/reviews/:review/approve') @ApiOperation({ summary: 'Approve book review' }) approveReview() { return stub(); }
  @Delete('books/:book/reviews/:review') @HttpCode(200) @ApiOperation({ summary: 'Delete book review' }) deleteReview() { return stub(); }

  // ── Rulesets (stubs) ──────────────────────────────────────────────────────────

  @Get('rulesets') @ApiOperation({ summary: 'List rulesets' }) rulesets() { return { data: [], meta: { total: 0 } }; }
  @Get('rulesets/:ruleset') @ApiOperation({ summary: 'Get ruleset' }) ruleset() { return stub(); }
  @Post('rulesets') @HttpCode(201) @ApiOperation({ summary: 'Create ruleset' }) createRuleset() { return stub(); }
  @Patch('rulesets/:ruleset') @ApiOperation({ summary: 'Update ruleset' }) updateRuleset() { return stub(); }

  // ── Transfer outbox ───────────────────────────────────────────────────────────

  @Get('transfer-outbox')
  @ApiOperation({ summary: 'List transfer outbox' })
  transferOutbox(
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.adminService.listTransferOutbox(page, perPage);
  }

  @Get('transfer-outbox/:id')
  @ApiOperation({ summary: 'Get transfer outbox item' })
  transferOutboxItem(@Param('id', ParseIntPipe) id: number) {
    return this.adminService.getTransferOutboxItem(id);
  }

  // ── Connected payouts (stubs) ─────────────────────────────────────────────────

  @Get('connected-payouts') @ApiOperation({ summary: 'List connected payouts' }) connectedPayouts() { return { data: [], meta: { total: 0 } }; }
  @Get('connected-payouts/:id') @ApiOperation({ summary: 'Get connected payout' }) connectedPayout() { return stub(); }

  // ── Auctions admin ────────────────────────────────────────────────────────────

  @Post('auctions')
  @HttpCode(201)
  @ApiOperation({ summary: 'Create auction (admin)' })
  createAuction(@Body() body: { title: string; slug: string; starts_at: string; ends_at: string; ruleset_id?: number }) {
    return this.adminService.createAuction(body);
  }

  @Patch('auctions/:auction')
  @ApiOperation({ summary: 'Update auction (admin)' })
  updateAuction(
    @Param('auction', ParseIntPipe) auctionId: number,
    @Body() body: { title?: string; starts_at?: string; ends_at?: string },
  ) {
    return this.adminService.updateAuction(auctionId, body);
  }

  @Post('auctions/:auction/items') @HttpCode(201) @ApiOperation({ summary: 'Add auction item' }) addAuctionItem() { return stub(); }
  @Delete('auctions/:auction/items/:item') @HttpCode(200) @ApiOperation({ summary: 'Remove auction item' }) removeAuctionItem() { return stub(); }

  @Post('auctions/:auction/publish')
  @HttpCode(200)
  @ApiOperation({ summary: 'Publish auction' })
  publishAuction(@Param('auction', ParseIntPipe) auctionId: number) {
    return this.adminService.publishAuction(auctionId);
  }

  @Post('auctions/:auction/open')
  @HttpCode(200)
  @ApiOperation({ summary: 'Open auction' })
  openAuction(@Param('auction', ParseIntPipe) auctionId: number) {
    return this.adminService.openAuction(auctionId);
  }

  @Post('auctions/:auction/close')
  @HttpCode(200)
  @ApiOperation({ summary: 'Close auction' })
  closeAuction(@Param('auction', ParseIntPipe) auctionId: number) {
    return this.adminService.closeAuction(auctionId);
  }
}
