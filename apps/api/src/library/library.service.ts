import { Injectable } from '@nestjs/common';
import { PrismaService } from '../prisma.service';
import { S3Service } from '../s3/s3.service';

export interface BooksQuery {
  featured?: boolean;
  is_free?: boolean;
  language?: string;
  q?: string;
  page?: number;
  perPage?: number;
}

@Injectable()
export class LibraryService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly s3: S3Service,
  ) {}

  async listBooks(query: BooksQuery = {}) {
    const { featured, is_free, language, q, page = 1, perPage = 20 } = query;
    const skip = (page - 1) * perPage;

    const where: any = {
      status: 'published',
      deleted_at: null,
      ...(featured ? { is_featured: true } : {}),
      ...(is_free ? { is_free: true } : {}),
      ...(language ? { language } : {}),
      ...(q
        ? {
            OR: [
              { title: { contains: q } },
              { short_description: { contains: q } },
            ],
          }
        : {}),
    };

    const [data, total] = await this.prisma.$transaction([
      this.prisma.book.findMany({
        where,
        include: {
          // 'authors' is the Prisma relation name for BookAuthor[] on Book
          // Each BookAuthor includes its author User — matches Book::with(['bookAuthors.author:id,name'])
          authors: {
            orderBy: { sort_order: 'asc' },
            include: {
              author: { select: { id: true, name: true } },
            },
          },
        },
        orderBy: [{ is_featured: 'desc' }, { created_at: 'desc' }],
        skip,
        take: perPage,
      }),
      this.prisma.book.count({ where }),
    ]);

    return {
      data: data.map((b) => this.toDto(b)),
      meta: {
        current_page: page,
        last_page: Math.max(1, Math.ceil(total / perPage)),
        per_page: perPage,
        total,
      },
      links: {
        next: page * perPage < total ? `?page=${page + 1}` : null,
        prev: page > 1 ? `?page=${page - 1}` : null,
      },
    };
  }

  async getBook(slug: string) {
    return this.prisma.book.findFirst({
      where: { slug, status: 'published', deleted_at: null },
      include: {
        authors: {
          orderBy: { sort_order: 'asc' },
          include: {
            author: { select: { id: true, name: true } },
          },
        },
      },
    });
  }

  async myBooks(userId: number, page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const [data, total] = await this.prisma.$transaction([
      this.prisma.book_entitlements.findMany({
        where: { user_id: BigInt(userId), revoked_at: null },
        include: { books: { select: { id: true, title: true, slug: true, language: true, page_count: true } } },
        orderBy: [{ granted_at: 'desc' }],
        skip,
        take: perPage,
      }),
      this.prisma.book_entitlements.count({ where: { user_id: BigInt(userId), revoked_at: null } }),
    ]);
    return {
      data,
      meta: { current_page: page, last_page: Math.max(1, Math.ceil(total / perPage)), per_page: perPage, total },
    };
  }

  async bookDownloadUrl(slug: string, userId: number) {
    const entitlement = await this.prisma.book_entitlements.findFirst({
      where: { user_id: BigInt(userId), revoked_at: null, books: { slug } },
    });
    if (!entitlement) throw new Error('No entitlement for this book');
    const key = `books/${slug}/download.pdf`;
    const download_url = this.s3.enabled
      ? await this.s3.presignDownload(key)
      : this.s3.stubDownloadUrl(key);
    return { download_url, expires_at: new Date(Date.now() + 3600_000).toISOString() };
  }

  async getBookReviews(slug: string, page = 1, perPage = 20) {
    return { data: [], meta: { current_page: page, last_page: 1, per_page: perPage, total: 0 } };
  }

  async createReview(_slug: string, _userId: number, _dto: { rating: number; comment?: string }) {
    return { id: 1, status: 'pending', created_at: new Date().toISOString() };
  }

  async getEntitlement(slug: string, userId: number) {
    const entitlement = await this.prisma.book_entitlements.findFirst({
      where: { user_id: BigInt(userId), revoked_at: null, books: { slug } },
    });
    return { has_access: !!entitlement, source: entitlement?.source ?? null };
  }

  async purchaseBook(_slug: string, _userId: number) {
    return { status: 'pending', message: 'Purchase initiated. Please complete payment.' };
  }

  async checkoutSession(_slug: string, _userId: number) {
    return { checkout_url: null, session_id: null, message: 'Checkout session not available in this environment.' };
  }

  async grantAccess(_slug: string, _userId: number, _granteeId: number) {
    return { granted: true };
  }

  async unpublishBook(slug: string) {
    await this.prisma.$executeRaw`UPDATE books SET status = 'draft', updated_at = UTC_TIMESTAMP() WHERE slug = ${slug} AND deleted_at IS NULL`;
    return { slug, status: 'draft' };
  }

  async publishBook(slug: string): Promise<{ slug: string; status: string }> {
    await this.prisma.$executeRaw`
      UPDATE books SET status = 'published', updated_at = UTC_TIMESTAMP()
      WHERE slug = ${slug} AND deleted_at IS NULL
    `;
    return { slug, status: 'published' };
  }

  // Shape matches the Laravel LibraryController response so the React frontend
  // requires no changes when pointed at this endpoint.
  //
  // Laravel serialises Book::with(['bookAuthors.author:id,name']) as:
  //   { ..book fields.., book_authors: [{ id, book_id, author_id, share_bps, sort_order, author: {id, name} }] }
  private toDto(book: any) {
    return {
      id: book.id,
      title: book.title,
      slug: book.slug,
      description: book.description,
      short_description: book.short_description,
      isbn: book.isbn,
      publisher: book.publisher,
      language: book.language,
      page_count: book.page_count,
      publication_year: book.publication_year,
      price_cents: book.price_cents,
      currency: book.currency,
      is_free: book.is_free,
      is_featured: book.is_featured,
      status: book.status,
      created_at: book.created_at,
      updated_at: book.updated_at,
      // access is injected per-request when the user is authenticated (not here)
      book_authors: (book.authors ?? []).map((ba: any) => ({
        id: ba.id,
        book_id: ba.book_id,
        author_id: ba.author_id,
        share_bps: ba.share_bps,
        sort_order: ba.sort_order,
        author: ba.author
          ? { id: ba.author.id, name: ba.author.name }
          : null,
      })),
    };
  }
}
