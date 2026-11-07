/**
 * Integration tests for PublicationListPage.
 *
 * HTTP mocked via vi.mock('@/lib/api').
 * Tests cover: loading spinner, empty state, publication cards (title, author,
 * description, page count), link href, access badge variants (purchased/free/
 * priced), short_description shown/hidden.
 */

import { render, screen, waitFor } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import PublicationListPage from './PublicationListPage';

// ── Fake API ──────────────────────────────────────────────────────────────────

const { mockGet } = vi.hoisted(() => ({ mockGet: vi.fn() }));

vi.mock('@/lib/api', async (importOriginal) => {
    const mod = await importOriginal<typeof import('@/lib/api')>();
    return { ...mod, api: { get: mockGet } };
});

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeQueryClient() {
    return new QueryClient({ defaultOptions: { queries: { retry: false } } });
}

function Wrapper({ children }: { children: React.ReactNode }) {
    return (
        <MemoryRouter>
            <QueryClientProvider client={makeQueryClient()}>
                {children}
            </QueryClientProvider>
        </MemoryRouter>
    );
}

function makePub(overrides: Record<string, unknown> = {}) {
    return {
        id: 1,
        title: 'Съвременното изкуство',
        slug: 'savremennoto-izkustvo',
        description: 'Подробно изследване.',
        short_description: 'Кратко описание.',
        isbn: null,
        publisher: null,
        language: 'bg',
        page_count: 240,
        publication_year: 2025,
        price_cents: 1500,
        currency: 'EUR',
        is_free: false,
        status: 'published',
        is_featured: false,
        created_at: '2026-01-01T00:00:00Z',
        book_authors: [{ author: { id: 1, name: 'Иван Петров', role: null, royalty_bps: null }, role: 'author' }],
        access: 'none',
        ...overrides,
    };
}

function makePaginatedResponse(items: unknown[]) {
    return { data: { data: items, meta: { current_page: 1, last_page: 1, per_page: 15, total: items.length } } };
}

beforeEach(() => {
    vi.clearAllMocks();
});

// ── 1. Loading ────────────────────────────────────────────────────────────────

describe('PublicationListPage — loading', () => {
    it('shows spinner while data loads', () => {
        mockGet.mockReturnValue(new Promise(() => {}));
        render(<Wrapper><PublicationListPage /></Wrapper>);
        expect(screen.getByRole('status')).toBeInTheDocument();
    });
});

// ── 2. Empty state ────────────────────────────────────────────────────────────

describe('PublicationListPage — empty state', () => {
    it('shows empty state when no publications', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([]));
        render(<Wrapper><PublicationListPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText(/no publications yet/i)).toBeInTheDocument());
    });

    it('renders page heading', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([]));
        render(<Wrapper><PublicationListPage /></Wrapper>);
        await waitFor(() => screen.getByText(/no publications yet/i));
        expect(screen.getByRole('heading', { name: /digital library/i })).toBeInTheDocument();
    });
});

// ── 3. Publication card ───────────────────────────────────────────────────────

describe('PublicationListPage — publication cards', () => {
    it('renders publication title as a link to /library/:slug', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makePub()]));
        render(<Wrapper><PublicationListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Съвременното изкуство'));
        const link = screen.getByRole('link', { name: /съвременното изкуство/i });
        expect(link).toHaveAttribute('href', '/library/savremennoto-izkustvo');
    });

    it('shows author name', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makePub()]));
        render(<Wrapper><PublicationListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Съвременното изкуство'));
        expect(screen.getByText('Иван Петров')).toBeInTheDocument();
    });

    it('shows page count with pp. suffix', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makePub()]));
        render(<Wrapper><PublicationListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Съвременното изкуство'));
        expect(screen.getByText('240 pp.')).toBeInTheDocument();
    });

    it('shows short_description', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makePub()]));
        render(<Wrapper><PublicationListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Кратко описание.'));
    });

    it('omits author row when no book_authors', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makePub({ book_authors: [] })]));
        render(<Wrapper><PublicationListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Съвременното изкуство'));
        expect(screen.queryByText('Иван Петров')).not.toBeInTheDocument();
    });

    it('omits page count when null', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makePub({ page_count: null })]));
        render(<Wrapper><PublicationListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Съвременното изкуство'));
        expect(screen.queryByText(/pp\./)).not.toBeInTheDocument();
    });
});

// ── 4. Access badge variants ──────────────────────────────────────────────────

describe('PublicationListPage — access badges', () => {
    it('shows Purchased badge when access is purchased', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makePub({ access: 'purchased' })]));
        render(<Wrapper><PublicationListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Съвременното изкуство'));
        expect(screen.getByText('Purchased')).toBeInTheDocument();
    });

    it('shows Free badge when is_free is true', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makePub({ is_free: true })]));
        render(<Wrapper><PublicationListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Съвременното изкуство'));
        expect(screen.getByText('Free')).toBeInTheDocument();
    });

    it('shows EUR price when not free and not purchased', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makePub({ price_cents: 2000, is_free: false, access: 'none' })]));
        render(<Wrapper><PublicationListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Съвременното изкуство'));
        expect(screen.getByText(/€20\.00/)).toBeInTheDocument();
    });

    it('renders multiple publications', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([
            makePub({ id: 1, title: 'Book One', slug: 'book-one' }),
            makePub({ id: 2, title: 'Book Two', slug: 'book-two' }),
        ]));
        render(<Wrapper><PublicationListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Book One'));
        expect(screen.getByText('Book Two')).toBeInTheDocument();
    });
});
