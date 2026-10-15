/**
 * Integration tests for artist DashboardPage.
 *
 * HTTP mocked via vi.mock('@/lib/api'). Auth context injected via Wrapper.
 * Tests cover: unauthenticated state, non-artist role gate, loading spinner,
 * empty state, artwork list rendering, status badges, edit/view links,
 * status count summary, Add artwork CTA link.
 */

import { render, screen, waitFor } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthContext } from '@/context/AuthContext';
import type { AuthUser } from '@/lib/auth';
import DashboardPage from './DashboardPage';

// ── Fake API ──────────────────────────────────────────────────────────────────

const { mockGet } = vi.hoisted(() => ({ mockGet: vi.fn() }));

vi.mock('@/lib/api', async (importOriginal) => {
    const mod = await importOriginal<typeof import('@/lib/api')>();
    return { ...mod, api: { get: mockGet } };
});

// ── Helpers ───────────────────────────────────────────────────────────────────

const ARTIST: AuthUser = { id: 1, name: 'Иван Петров', email: 'ivan@test.com', role: 'artist' };
const BUYER:  AuthUser = { id: 2, name: 'Buyer', email: 'b@test.com', role: 'buyer' };

function makeQueryClient() {
    return new QueryClient({ defaultOptions: { queries: { retry: false } } });
}

function Wrapper({ user = ARTIST as AuthUser | null, children }: {
    user?: AuthUser | null;
    children: React.ReactNode;
}) {
    const ctx = {
        user,
        loading: false,
        refresh: vi.fn(),
        logout: vi.fn(),
    };
    return (
        <MemoryRouter>
            <QueryClientProvider client={makeQueryClient()}>
                <AuthContext.Provider value={ctx}>
                    {children}
                </AuthContext.Provider>
            </QueryClientProvider>
        </MemoryRouter>
    );
}

function makeArtwork(overrides: Partial<{
    id: number; title: string; slug: string; status: string; medium: string | null; year_created: number | null;
}> = {}) {
    return {
        id: overrides.id ?? 1,
        title: overrides.title ?? 'Изгрев над София',
        slug: overrides.slug ?? 'izgrev-nad-sofiya',
        status: overrides.status ?? 'draft',
        medium: overrides.medium ?? 'painting',
        year_created: overrides.year_created ?? 2024,
        is_original: true,
    };
}

function makePaginatedResponse(items: unknown[]) {
    return { data: { data: items, meta: { current_page: 1, last_page: 1, per_page: 15, total: items.length } } };
}

beforeEach(() => {
    vi.clearAllMocks();
});

// ── 1. Auth guards ────────────────────────────────────────────────────────────

describe('DashboardPage — auth guards', () => {
    it('shows sign-in prompt when unauthenticated', () => {
        render(<Wrapper user={null}><DashboardPage /></Wrapper>);
        expect(screen.getByText(/you need to sign in/i)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /sign in/i })).toBeInTheDocument();
    });

    it('shows artist-only message for non-artist role', () => {
        render(<Wrapper user={BUYER}><DashboardPage /></Wrapper>);
        expect(screen.getByText(/dashboard is available for artists only/i)).toBeInTheDocument();
    });

    it('does not call API when unauthenticated', () => {
        render(<Wrapper user={null}><DashboardPage /></Wrapper>);
        expect(mockGet).not.toHaveBeenCalled();
    });
});

// ── 2. Loading state ──────────────────────────────────────────────────────────

describe('DashboardPage — loading', () => {
    it('shows spinner while artworks are loading', () => {
        mockGet.mockReturnValue(new Promise(() => {}));
        render(<Wrapper><DashboardPage /></Wrapper>);
        expect(screen.getByRole('status')).toBeInTheDocument();
    });
});

// ── 3. Empty state ────────────────────────────────────────────────────────────

describe('DashboardPage — empty state', () => {
    it('shows empty state when artist has no artworks', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([]));
        render(<Wrapper><DashboardPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText(/no artworks yet/i)).toBeInTheDocument());
    });

    it('renders the heading and Add artwork link on empty state', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([]));
        render(<Wrapper><DashboardPage /></Wrapper>);
        await waitFor(() => screen.getByText(/no artworks yet/i));
        expect(screen.getByRole('heading', { name: /my artworks/i })).toBeInTheDocument();
        const addLinks = screen.getAllByRole('link', { name: /add artwork/i });
        expect(addLinks.length).toBeGreaterThanOrEqual(1);
    });
});

// ── 4. Artwork list ───────────────────────────────────────────────────────────

describe('DashboardPage — artwork list', () => {
    it('renders artwork title and medium · year', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeArtwork()]));
        render(<Wrapper><DashboardPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText('Изгрев над София')).toBeInTheDocument());
        expect(screen.getByText(/painting · 2024/i)).toBeInTheDocument();
    });

    it('shows "No medium" when medium is null', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeArtwork({ medium: null })]));
        render(<Wrapper><DashboardPage /></Wrapper>);
        await waitFor(() => screen.getByText('Изгрев над София'));
        expect(screen.getByText(/no medium/i)).toBeInTheDocument();
    });

    it('renders multiple artworks', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([
            makeArtwork({ id: 1, title: 'First Work', slug: 'first' }),
            makeArtwork({ id: 2, title: 'Second Work', slug: 'second', status: 'listed' }),
        ]));
        render(<Wrapper><DashboardPage /></Wrapper>);
        await waitFor(() => screen.getByText('First Work'));
        expect(screen.getByText('Second Work')).toBeInTheDocument();
    });
});

// ── 5. Status badges ──────────────────────────────────────────────────────────

describe('DashboardPage — status badges', () => {
    it('shows Draft badge for draft artwork', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeArtwork({ status: 'draft' })]));
        render(<Wrapper><DashboardPage /></Wrapper>);
        await waitFor(() => screen.getByText('Изгрев над София'));
        expect(screen.getAllByText('Draft').length).toBeGreaterThanOrEqual(1);
    });

    it('shows Listed badge for listed artwork', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeArtwork({ status: 'listed' })]));
        render(<Wrapper><DashboardPage /></Wrapper>);
        await waitFor(() => screen.getByText('Изгрев над София'));
        expect(screen.getAllByText('Listed').length).toBeGreaterThanOrEqual(1);
    });

    it('shows In Auction badge for in_auction artwork', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeArtwork({ status: 'in_auction' })]));
        render(<Wrapper><DashboardPage /></Wrapper>);
        await waitFor(() => screen.getByText('Изгрев над София'));
        expect(screen.getAllByText('In Auction').length).toBeGreaterThanOrEqual(1);
    });

    it('shows Sold badge for sold artwork', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeArtwork({ status: 'sold' })]));
        render(<Wrapper><DashboardPage /></Wrapper>);
        await waitFor(() => screen.getByText('Изгрев над София'));
        expect(screen.getAllByText('Sold').length).toBeGreaterThanOrEqual(1);
    });
});

// ── 6. Edit / View links ──────────────────────────────────────────────────────

describe('DashboardPage — artwork links', () => {
    it('shows Edit link for draft artworks', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeArtwork({ slug: 'my-work', status: 'draft' })]));
        render(<Wrapper><DashboardPage /></Wrapper>);
        await waitFor(() => screen.getByText('Изгрев над София'));
        const editLink = screen.getByRole('link', { name: /edit/i });
        expect(editLink).toHaveAttribute('href', '/dashboard/artworks/my-work/edit');
    });

    it('does not show Edit link for listed artworks', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeArtwork({ status: 'listed' })]));
        render(<Wrapper><DashboardPage /></Wrapper>);
        await waitFor(() => screen.getByText('Изгрев над София'));
        expect(screen.queryByRole('link', { name: /edit/i })).not.toBeInTheDocument();
    });

    it('shows View link for every artwork', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeArtwork({ slug: 'my-slug' })]));
        render(<Wrapper><DashboardPage /></Wrapper>);
        await waitFor(() => screen.getByText('Изгрев над София'));
        const viewLink = screen.getByRole('link', { name: /^view$/i });
        expect(viewLink).toHaveAttribute('href', '/artworks/my-slug');
    });

    it('Add artwork header button links to /dashboard/artworks/new', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeArtwork()]));
        render(<Wrapper><DashboardPage /></Wrapper>);
        await waitFor(() => screen.getByText('Изгрев над София'));
        const addLink = screen.getByRole('link', { name: /^\+ add artwork$/i });
        expect(addLink).toHaveAttribute('href', '/dashboard/artworks/new');
    });
});

// ── 7. Status count summary ───────────────────────────────────────────────────

describe('DashboardPage — status count summary', () => {
    it('shows count chips for each status represented', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([
            makeArtwork({ id: 1, status: 'draft' }),
            makeArtwork({ id: 2, status: 'draft' }),
            makeArtwork({ id: 3, status: 'listed' }),
        ]));
        render(<Wrapper><DashboardPage /></Wrapper>);
        await waitFor(() => screen.getByText('Изгрев над София'));
        // Each Badge in summary + row; at minimum the count "2" should appear
        const counts = screen.getAllByText('2');
        expect(counts.length).toBeGreaterThanOrEqual(1);
    });
});
