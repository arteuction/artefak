import {
  Controller,
  Get,
  Post,
  Patch,
  Param,
  Query,
  Body,
  NotFoundException,
  ParseIntPipe,
  DefaultValuePipe,
  UseGuards,
  HttpCode,
  HttpStatus,
} from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiQuery, ApiTags } from '@nestjs/swagger';
import { LibraryService } from './library.service';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { RolesGuard } from '../auth/roles.guard';
import { Roles } from '../auth/roles.decorator';
import { CurrentUser } from '../auth/current-user.decorator';
import { AuthenticatedUser } from '../auth/auth.types';

@ApiTags('library')
@Controller('books')
export class LibraryController {
  constructor(private readonly library: LibraryService) {}

  /** GET /api/v1/books — public catalogue, matches Laravel LibraryController#index shape */
  @Get()
  @ApiOperation({ summary: 'List published books' })
  @ApiQuery({ name: 'featured', required: false, type: Boolean })
  @ApiQuery({ name: 'is_free', required: false, type: Boolean })
  @ApiQuery({ name: 'language', required: false, type: String })
  @ApiQuery({ name: 'q', required: false, type: String })
  @ApiQuery({ name: 'page', required: false, type: Number })
  @ApiQuery({ name: 'per_page', required: false, type: Number })
  async index(
    @Query('featured') featured?: string,
    @Query('is_free') isFree?: string,
    @Query('language') language?: string,
    @Query('q') q?: string,
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.library.listBooks({
      featured: featured === '1' || featured === 'true',
      is_free: isFree === '1' || isFree === 'true',
      language,
      q,
      page,
      perPage,
    });
  }

  /** GET /api/v1/books/:slug */
  @Get(':slug')
  @ApiOperation({ summary: 'Show a published book by slug' })
  async show(@Param('slug') slug: string) {
    const book = await this.library.getBook(slug);
    if (!book) throw new NotFoundException(`Book "${slug}" not found`);
    return book;
  }

  /**
   * PATCH /api/v1/books/:slug/publish — admin or operator only.
   */
  @Patch(':slug/publish')
  @HttpCode(HttpStatus.OK)
  @UseGuards(JwtAuthGuard, RolesGuard)
  @Roles('admin', 'operator')
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Publish a book (admin/operator)' })
  async publish(@Param('slug') slug: string) {
    return this.library.publishBook(slug);
  }

  @Patch(':slug/unpublish')
  @HttpCode(HttpStatus.OK)
  @UseGuards(JwtAuthGuard, RolesGuard)
  @Roles('admin', 'operator')
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Unpublish a book (admin/operator)' })
  async unpublish(@Param('slug') slug: string) {
    return this.library.unpublishBook(slug);
  }

  @Get(':slug/reviews')
  @ApiOperation({ summary: 'List reviews for a book' })
  async getReviews(
    @Param('slug') slug: string,
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.library.getBookReviews(slug, page, perPage);
  }

  @Post(':slug/reviews')
  @HttpCode(201)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Submit a review for a book' })
  async createReview(
    @Param('slug') slug: string,
    @CurrentUser() user: AuthenticatedUser,
    @Body() body: { rating: number; comment?: string },
  ) {
    return this.library.createReview(slug, user.id, body);
  }

  @Get(':slug/entitlement')
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Check if user has access to a book' })
  async entitlement(@Param('slug') slug: string, @CurrentUser() user: AuthenticatedUser) {
    return this.library.getEntitlement(slug, user.id);
  }

  @Post(':slug/purchase')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Purchase a book' })
  async purchase(@Param('slug') slug: string, @CurrentUser() user: AuthenticatedUser) {
    return this.library.purchaseBook(slug, user.id);
  }

  @Post(':slug/checkout-session')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Create Stripe checkout session for book purchase' })
  async checkoutSession(@Param('slug') slug: string, @CurrentUser() user: AuthenticatedUser) {
    return this.library.checkoutSession(slug, user.id);
  }

  @Post(':slug/grant')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard, RolesGuard)
  @Roles('admin', 'operator')
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Grant book access to a user (admin/operator)' })
  async grant(
    @Param('slug') slug: string,
    @CurrentUser() user: AuthenticatedUser,
    @Body() body: { user_id: number },
  ) {
    return this.library.grantAccess(slug, user.id, body.user_id);
  }
}
