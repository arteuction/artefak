import { Controller, Get, Param, Query, UseGuards, DefaultValuePipe, ParseIntPipe } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { CurrentUser } from '../auth/current-user.decorator';
import { AuthenticatedUser } from '../auth/auth.types';
import { LibraryService } from './library.service';

@ApiTags('my-books')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard)
@Controller('my-books')
export class MyBooksController {
  constructor(private readonly library: LibraryService) {}

  @Get()
  @ApiOperation({ summary: "List authenticated user's purchased/entitled books" })
  myBooks(
    @CurrentUser() user: AuthenticatedUser,
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.library.myBooks(user.id, page, perPage);
  }

  @Get(':book/download-url')
  @ApiOperation({ summary: 'Get signed download URL for an entitled book' })
  downloadUrl(
    @Param('book') book: string,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.library.bookDownloadUrl(book, user.id);
  }
}
