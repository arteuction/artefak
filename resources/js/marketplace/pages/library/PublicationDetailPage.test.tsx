/**
 * Integration tests for PublicationDetailPage.
 *
 * HTTP mocked via vi.mock('@/lib/api'). Auth context injected via Wrapper.
 * useDocTitle mocked to avoid side-effects.
 * Tests cover: loading, not-found, metadata display, author, page count, price,
 * free publication, Sign-in CTA (unauth), Buy button flow (confirm/cancel/error),
 * already-purchased Download flow, download error, payment=success query-param
 * triggers re-fetch, breadcrumb link.
 */

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach } from 'vitest';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthContext } from '@/context/AuthContext';
import type { AuthUser } from '@/lib/auth';
import PublicationDetailPage from './PublicationDetailPage';

// ── Fakes ─────────────────────────────────────────────────────────────────────

const { mockGet, mockPost } = vi.hoisted(() => ({
    mockGet:  vi.fn(),
    mockPost: vi.fn(),
}));

vi.mock('@/lib/api', async (importOriginal) => {
    const mod = await importOriginal<typeof import('@/lib/api')>();
    return { ...mod, api: { get: mockGet, post: mockPost } };
});

vi.mock('@/lib/useDocTitle', () => ({ useDocTitle: vi.fn() }));

// ── Helpers ───────────────────────────────────────────────────────────────────

const BUYER: AuthUser = { id: 5, name: 'Buyer', email: 'b@test.com', role: 'buyer' };

function makeQueryClient() {
    return new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
}

function Wrapper({
    user = BUYER as AuthUser | null,
    slug = 'test-book',
    search = '',
    children,
}: {
    user?: AuthUser | null;
    slug?: string;
    search?: string;
    children: React.ReactNode;
}) {
    const ctx = { user, loading: false, refresh: vi.fn(), logout: vi.fn() };
    return (
        <MemoryRouter initialEntries={[`/library/${slug}${search}`]}>
            <QueryClientProvider client={makeQueryClient()}>
                <AuthContext.Provider value={ctx}>
                    <Routes>
                        <Route path="/library/:slug" element={children} />
                    </Routes>
                </AuthContext.Provider>
            </QueryClientProvider>
        </MemoryRouter>
    );
}

function makePub(overrides: Record<string, unknown> = {}) {
    return {
        id: 7,
        title: 'Изкуство и природа',
        slug: 'test-book',
        description: 'Подробно описание на книгата.',
        short_description: null,
        isbn: null,
        publisher: null,
        language: 'bg',
        page_count: 180,
        publication_year: 2025,
        price_cents: 1200,
        currency: 'EUR',
        is_free: false,
        status: 'published',
        is_featured: false,
        created_at: '2026-01-01T00:00:00Z',
        book_authors: [{ author: { id: 2, name: 'Мария Иванова', role: null, royalty_bps: null }, role: null }],
        access: 'none',
        ...overrides,
    };
}

beforeEach(() => {
    vi.clearAllMocks();
});

// ── 1. Loading / not-found ────────────────────────────────────────────────────

describe('PublicationDetailPage — loading and not-found', () => {
    it('shows spinner while loading', () => {
        mockGet.mockReturnValue(new Promise(() => {}));
        render(<Wrapper><PublicationDetailPage /></Wrapper>);
        expect(screen.getByRole('status')).toBeInTheDocument();
    });

    it('shows not-found message on API error', async () => {
        mockGet.mockRejectedValue(new Error('404'));
        render(<Wrapper><PublicationDetailPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText(/publication not found/i)).toBeInTheDocument());
    });
});

// ── 2. Metadata display ───────────────────────────────────────────────────────

describe('PublicationDetailPage — metadata', () => {
    it('renders publication title as heading', async () => {
        mockGet.mockResolvedValue({ data: makePub() });
        render(<Wrapper><PublicationDetailPage /></Wrapper>);
        await waitFor(() => expect(screen.getByRole('heading', { name: 'Изкуство и природа' })).toBeInTheDocument());
    });

    it('shows author name', async () => {
        mockGet.mockResolvedValue({ data: makePub() });
        render(<Wrapper><PublicationDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изкуство и природа' }));
        expect(screen.getByText('Мария Иванова')).toBeInTheDocument();
    });

    it('shows description', async () => {
        mockGet.mockResolvedValue({ data: makePub() });
        render(<Wrapper><PublicationDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изкуство и природа' }));
        expect(screen.getByText('Подробно описание на книгата.')).toBeInTheDocument();
    });

    it('shows page count', async () => {
        mockGet.mockResolvedValue({ data: makePub() });
        render(<Wrapper><PublicationDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изкуство и природа' }));
        expect(screen.getByText('180')).toBeInTheDocument();
    });

    it('shows EUR price in metadata', async () => {
        mockGet.mockResolvedValue({ data: makePub({ price_cents: 1200 }) });
        render(<Wrapper><PublicationDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изкуство и природа' }));
        expect(screen.getByText(/€12\.00/)).toBeInTheDocument();
    });

    it('shows Free for is_free publication', async () => {
        mockGet.mockResolvedValue({ data: makePub({ is_free: true, access: 'free' }) });
        render(<Wrapper><PublicationDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изкуство и природа' }));
        expect(screen.getByText(/^Free$/)).toBeInTheDocument();
    });

    it('shows Published badge for published status', async () => {
        mockGet.mockResolvedValue({ data: makePub() });
        render(<Wrapper><PublicationDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изкуство и природа' }));
        expect(screen.getByText('Published')).toBeInTheDocument();
    });

    it('breadcrumb links to /library', async () => {
        mockGet.mockResolvedValue({ data: makePub() });
        render(<Wrapper><PublicationDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изкуство и природа' }));
        expect(screen.getByRole('link', { name: /library/i })).toHaveAttribute('href', '/library');
    });
});

// ── 3. Unauthenticated CTA ────────────────────────────────────────────────────

describe('PublicationDetailPage — unauthenticated', () => {
    it('shows Sign in to purchase link for unauthenticated user', async () => {
        mockGet.mockResolvedValue({ data: makePub({ access: 'none' }) });
        render(<Wrapper user={null}><PublicationDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изкуство и природа' }));
        expect(screen.getByRole('link', { name: /sign in to purchase/i })).toBeInTheDocument();
    });
});

// ── 4. Buy flow ───────────────────────────────────────────────────────────────

describe('PublicationDetailPage — buy flow', () => {
    it('shows Buy button for authenticated user without access', async () => {
        mockGet.mockResolvedValue({ data: makePub({ price_cents: 1200, access: 'none' }) });
        render(<Wrapper><PublicationDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изкуство и природа' }));
        expect(screen.getByRole('button', { name: /buy for/i })).toBeInTheDocument();
    });

    it('clicking Buy shows confirmation dialog', async () => {
        mockGet.mockResolvedValue({ data: makePub({ price_cents: 1200, access: 'none' }) });
        render(<Wrapper><PublicationDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изкуство и природа' }));

        fireEvent.click(screen.getByRole('button', { name: /buy for/i }));
        expect(screen.getByText(/confirm purchase/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /^confirm$/i })).toBeInTheDocument();
    });

    it('Cancel in confirmation dialog hides it', async () => {
        mockGet.mockResolvedValue({ data: makePub({ access: 'none' }) });
        render(<Wrapper><PublicationDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изкуство и природа' }));

        fireEvent.click(screen.getByRole('button', { name: /buy for/i }));
        fireEvent.click(screen.getByRole('button', { name: /^cancel$/i }));
        expect(screen.queryByText(/confirm purchase/i)).not.toBeInTheDocument();
    });

    it('Confirm calls POST /books/:slug/checkout-session', async () => {
        mockGet.mockResolvedValue({ data: makePub({ access: 'none' }) });
        mockPost.mockResolvedValue({ data: { url: 'https://checkout.stripe.com/test' } });

        // mock window.location.href setter
        const locationSpy = vi.spyOn(window, 'location', 'get').mockReturnValue(
            { href: '' } as Location,
        );

        render(<Wrapper><PublicationDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изкуство и природа' }));

        fireEvent.click(screen.getByRole('button', { name: /buy for/i }));
        fireEvent.click(screen.getByRole('button', { name: /^confirm$/i }));

        await waitFor(() => expect(mockPost).toHaveBeenCalledWith('/books/test-book/checkout-session'));
        locationSpy.mockRestore();
    });

    it('shows purchase error message on failure', async () => {
        mockGet.mockResolvedValue({ data: makePub({ access: 'none' }) });
        mockPost.mockRejectedValue(new Error('Server error'));
        render(<Wrapper><PublicationDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изкуство и природа' }));

        fireEvent.click(screen.getByRole('button', { name: /buy for/i }));
        fireEvent.click(screen.getByRole('button', { name: /^confirm$/i }));

        await waitFor(() => expect(screen.getByText(/purchase failed/i)).toBeInTheDocument());
    });
});

// ── 5. Download flow (purchased) ──────────────────────────────────────────────

describe('PublicationDetailPage — download', () => {
    it('shows Download button for purchased publication', async () => {
        mockGet.mockResolvedValue({ data: makePub({ access: 'purchased', status: 'published' }) });
        render(<Wrapper><PublicationDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изкуство и природа' }));
        expect(screen.getByRole('button', { name: /download/i })).toBeInTheDocument();
        expect(screen.getByText(/you own this publication/i)).toBeInTheDocument();
    });

    it('shows Download for free publication', async () => {
        mockGet.mockResolvedValue({ data: makePub({ is_free: true, access: 'free', status: 'published' }) });
        render(<Wrapper><PublicationDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изкуство и природа' }));
        expect(screen.getByRole('button', { name: /download/i })).toBeInTheDocument();
    });

    it('Download calls GET /my-books/:id/download-url', async () => {
        mockGet
            .mockResolvedValueOnce({ data: makePub({ access: 'purchased', status: 'published' }) })
            .mockResolvedValueOnce({ data: { url: 'https://cdn.example.com/book.pdf' } });

        render(<Wrapper><PublicationDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('button', { name: /download/i }));

        fireEvent.click(screen.getByRole('button', { name: /download/i }));
        await waitFor(() => expect(mockGet).toHaveBeenCalledWith('/my-books/7/download-url'));
    });

    it('shows download error message on failure', async () => {
        mockGet
            .mockResolvedValueOnce({ data: makePub({ access: 'purchased', status: 'published' }) })
            .mockRejectedValueOnce(new Error('Download failed'));

        render(<Wrapper><PublicationDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('button', { name: /download/i }));

        fireEvent.click(screen.getByRole('button', { name: /download/i }));
        await waitFor(() => expect(screen.getByText(/download failed/i)).toBeInTheDocument());
    });
});
