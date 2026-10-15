/**
 * Integration tests for ArtistProfilePage.
 *
 * Tests cover: loading, not-found error, profile header (display name, bio,
 * website, Instagram), artworks grid, empty works section, recent auction
 * results section, breadcrumb navigation, and Bulgarian Cyrillic content.
 */

import { render, screen, waitFor } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach } from 'vitest';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthContext } from '@/context/AuthContext';
import ArtistProfilePage from './ArtistProfilePage';

// ── Fake API ──────────────────────────────────────────────────────────────────

const { mockGet } = vi.hoisted(() => ({ mockGet: vi.fn() }));

vi.mock('@/lib/api', async (importOriginal) => {
    const mod = await importOriginal<typeof import('@/lib/api')>();
    return { ...mod, api: { get: mockGet } };
});

// ── Wrapper ───────────────────────────────────────────────────────────────────

function makeQueryClient() {
    return new QueryClient({ defaultOptions: { queries: { retry: false } } });
}

function Wrapper({ slug = 'ivan-petrov', children }: {
    slug?: string;
    children: React.ReactNode;
}) {
    return (
        <QueryClientProvider client={makeQueryClient()}>
            <AuthContext.Provider value={{ user: null, loading: false, refresh: async () => {} }}>
                <MemoryRouter initialEntries={[`/artists/${slug}`]}>
                    <Routes>
                        <Route path="/artists/:slug" element={children} />
                    </Routes>
                </MemoryRouter>
            </AuthContext.Provider>
        </QueryClientProvider>
    );
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

function makeArtwork(id: number) {
    return {
        id,
        title: `Artwork ${id}`,
        slug: `artwork-${id}`,
        status: 'listed',
        medium: 'painting',
        year_created: 2024,
        is_original: true,
        currency: 'EUR',
        artist: { id: 1, name: 'Ivan Petrov', profile_slug: 'ivan-petrov' },
    };
}

function makePortfolio(overrides: Record<string, unknown> = {}) {
    return {
        profile: {
            display_name: 'Ivan Petrov',
            bio: 'A Bulgarian contemporary artist.',
            website: 'https://ivanpetrov.art',
            instagram_handle: 'ivanpetrovart',
        },
        artworks: {
            data: [makeArtwork(1), makeArtwork(2)],
            meta: { total: 2, current_page: 1, last_page: 1, per_page: 16 },
        },
        recent_sales: [],
        ...overrides,
    };
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
    vi.clearAllMocks();
});

// ── 1. Loading and error states ───────────────────────────────────────────────

describe('ArtistProfilePage — loading and error', () => {
    it('shows a spinner while loading', () => {
        mockGet.mockReturnValue(new Promise(() => {}));
        render(<Wrapper><ArtistProfilePage /></Wrapper>);
        expect(screen.getByRole('status')).toBeInTheDocument();
    });

    it('shows not-found message on API error', async () => {
        mockGet.mockRejectedValue(new Error('Not Found'));
        render(<Wrapper><ArtistProfilePage /></Wrapper>);
        await waitFor(() => expect(screen.getByText(/artist profile not found/i)).toBeInTheDocument());
    });

    it('shows browse artworks link on error', async () => {
        mockGet.mockRejectedValue(new Error('Not Found'));
        render(<Wrapper><ArtistProfilePage /></Wrapper>);
        await waitFor(() => expect(screen.getByRole('link', { name: /browse artworks/i })).toBeInTheDocument());
    });
});

// ── 2. Profile header ─────────────────────────────────────────────────────────

describe('ArtistProfilePage — profile header', () => {
    it('renders artist display name as heading', async () => {
        mockGet.mockResolvedValue({ data: makePortfolio() });
        render(<Wrapper><ArtistProfilePage /></Wrapper>);
        await waitFor(() => expect(screen.getByRole('heading', { name: 'Ivan Petrov' })).toBeInTheDocument());
    });

    it('renders artist bio', async () => {
        mockGet.mockResolvedValue({ data: makePortfolio() });
        render(<Wrapper><ArtistProfilePage /></Wrapper>);
        await waitFor(() => expect(screen.getByText('A Bulgarian contemporary artist.')).toBeInTheDocument());
    });

    it('renders website link', async () => {
        mockGet.mockResolvedValue({ data: makePortfolio() });
        render(<Wrapper><ArtistProfilePage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Ivan Petrov' }));
        const websiteLink = screen.getByRole('link', { name: 'Website' });
        expect(websiteLink).toHaveAttribute('href', 'https://ivanpetrov.art');
        expect(websiteLink).toHaveAttribute('target', '_blank');
        expect(websiteLink).toHaveAttribute('rel', 'noopener noreferrer');
    });

    it('renders Instagram link', async () => {
        mockGet.mockResolvedValue({ data: makePortfolio() });
        render(<Wrapper><ArtistProfilePage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Ivan Petrov' }));
        const igLink = screen.getByRole('link', { name: /@ivanpetrovart/ });
        expect(igLink).toHaveAttribute('href', 'https://instagram.com/ivanpetrovart');
    });

    it('does not render website link when not set', async () => {
        mockGet.mockResolvedValue({ data: makePortfolio({ profile: { display_name: 'Ivan Petrov', bio: null, website: null, instagram_handle: null } }) });
        render(<Wrapper><ArtistProfilePage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Ivan Petrov' }));
        expect(screen.queryByRole('link', { name: 'Website' })).not.toBeInTheDocument();
    });

    it('renders breadcrumb with artist name', async () => {
        mockGet.mockResolvedValue({ data: makePortfolio() });
        render(<Wrapper><ArtistProfilePage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Ivan Petrov' }));
        // Breadcrumb shows the artist name (in a nav element)
        const nav = screen.getByRole('navigation');
        expect(nav).toHaveTextContent('Ivan Petrov');
    });
});

// ── 3. Artworks section ───────────────────────────────────────────────────────

describe('ArtistProfilePage — artworks section', () => {
    it('renders artworks grid with total count', async () => {
        mockGet.mockResolvedValue({ data: makePortfolio() });
        render(<Wrapper><ArtistProfilePage /></Wrapper>);
        await waitFor(() => screen.getByText('Artwork 1'));
        expect(screen.getByText('Artwork 2')).toBeInTheDocument();
        // Total count displayed near the Works heading
        expect(screen.getByText(/\(2\)/)).toBeInTheDocument();
    });

    it('shows empty works state when artist has no artworks', async () => {
        mockGet.mockResolvedValue({
            data: makePortfolio({
                artworks: {
                    data: [],
                    meta: { total: 0, current_page: 1, last_page: 1, per_page: 16 },
                },
            }),
        });
        render(<Wrapper><ArtistProfilePage /></Wrapper>);
        await waitFor(() => expect(screen.getByText(/no published works yet/i)).toBeInTheDocument());
    });
});

// ── 4. Recent auction results ─────────────────────────────────────────────────

describe('ArtistProfilePage — recent auction results', () => {
    it('hides results section when no recent sales', async () => {
        mockGet.mockResolvedValue({ data: makePortfolio({ recent_sales: [] }) });
        render(<Wrapper><ArtistProfilePage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Ivan Petrov' }));
        expect(screen.queryByText(/recent auction results/i)).not.toBeInTheDocument();
    });

    it('shows recent auction results when present', async () => {
        mockGet.mockResolvedValue({
            data: makePortfolio({
                recent_sales: [
                    { auction_item_id: 99, title: 'Mountain Landscape', slug: 'mountain-landscape' },
                ],
            }),
        });
        render(<Wrapper><ArtistProfilePage /></Wrapper>);
        await waitFor(() => expect(screen.getByText(/recent auction results/i)).toBeInTheDocument());
        expect(screen.getByRole('link', { name: 'Mountain Landscape' })).toBeInTheDocument();
        expect(screen.getByText('Sold')).toBeInTheDocument();
    });
});

// ── 5. Cyrillic content ───────────────────────────────────────────────────────

describe('ArtistProfilePage — Bulgarian Cyrillic content', () => {
    it('renders Cyrillic artist name and bio correctly', async () => {
        mockGet.mockResolvedValue({
            data: makePortfolio({
                profile: {
                    display_name: 'Иван Петров',
                    bio: 'Български съвременен художник.',
                    website: null,
                    instagram_handle: null,
                },
            }),
        });
        render(<Wrapper slug="ivan-petrov"><ArtistProfilePage /></Wrapper>);
        await waitFor(() =>
            expect(screen.getByRole('heading', { name: 'Иван Петров' })).toBeInTheDocument()
        );
        expect(screen.getByText('Български съвременен художник.')).toBeInTheDocument();
    });
});
