/**
 * Integration tests for VenueDetailPage (ArtMetro venue detail).
 *
 * HTTP mocked via vi.mock('@/lib/api').
 * Tests cover: loading, not-found, venue name/city/address/description,
 * website link (target/rel), map link (lat+lng → OSM URL), empty artworks
 * state, artwork list (title link, artist, medium, location_label), IIIF
 * viewer link, breadcrumb.
 */

import { render, screen, waitFor } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach } from 'vitest';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import VenueDetailPage from './VenueDetailPage';

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

function Wrapper({ slug = 'test-venue', children }: { slug?: string; children: React.ReactNode }) {
    return (
        <MemoryRouter initialEntries={[`/metro/${slug}`]}>
            <QueryClientProvider client={makeQueryClient()}>
                <Routes>
                    <Route path="/metro/:slug" element={children} />
                </Routes>
            </QueryClientProvider>
        </MemoryRouter>
    );
}

function makeVenue(overrides: Record<string, unknown> = {}) {
    return {
        id: 1,
        name: 'Национална галерия',
        slug: 'test-venue',
        address: 'пл. „Александър Батенберг" 1',
        city: 'София',
        description: 'Главната художествена галерия.',
        lat: 42.697,
        lng: 23.332,
        website_url: 'https://nationalgallery.bg',
        status: 'active',
        artworks: [],
        ...overrides,
    };
}

function makeVenueArtwork(overrides: Record<string, unknown> = {}) {
    return {
        id: 10,
        artwork: {
            id: 5,
            title: 'Изгрев',
            slug: 'izgrev',
            medium: 'oil',
            artist: { id: 2, name: 'Иван Петров' },
        },
        location_label: 'Зала 3, стена А',
        iiif_manifest_url: null,
        qr_token: null,
        ...overrides,
    };
}

beforeEach(() => {
    vi.clearAllMocks();
});

// ── 1. Loading / not-found ────────────────────────────────────────────────────

describe('VenueDetailPage — loading and not-found', () => {
    it('shows spinner while data loads', () => {
        mockGet.mockReturnValue(new Promise(() => {}));
        render(<Wrapper><VenueDetailPage /></Wrapper>);
        expect(screen.getByRole('status')).toBeInTheDocument();
    });

    it('shows not-found on API error', async () => {
        mockGet.mockRejectedValue(new Error('404'));
        render(<Wrapper><VenueDetailPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText(/venue not found/i)).toBeInTheDocument());
    });
});

// ── 2. Venue metadata ─────────────────────────────────────────────────────────

describe('VenueDetailPage — venue metadata', () => {
    it('renders venue name as heading', async () => {
        mockGet.mockResolvedValue({ data: makeVenue() });
        render(<Wrapper><VenueDetailPage /></Wrapper>);
        await waitFor(() => expect(screen.getByRole('heading', { name: 'Национална галерия' })).toBeInTheDocument());
    });

    it('shows city', async () => {
        mockGet.mockResolvedValue({ data: makeVenue() });
        render(<Wrapper><VenueDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Национална галерия' }));
        expect(screen.getByText('София')).toBeInTheDocument();
    });

    it('shows address', async () => {
        mockGet.mockResolvedValue({ data: makeVenue() });
        render(<Wrapper><VenueDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Национална галерия' }));
        expect(screen.getByText(/батенберг/i)).toBeInTheDocument();
    });

    it('shows description', async () => {
        mockGet.mockResolvedValue({ data: makeVenue() });
        render(<Wrapper><VenueDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Национална галерия' }));
        expect(screen.getByText(/главната художествена/i)).toBeInTheDocument();
    });

    it('breadcrumb links to /metro', async () => {
        mockGet.mockResolvedValue({ data: makeVenue() });
        render(<Wrapper><VenueDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Национална галерия' }));
        expect(screen.getByRole('link', { name: /artmetro/i })).toHaveAttribute('href', '/metro');
    });
});

// ── 3. External links ─────────────────────────────────────────────────────────

describe('VenueDetailPage — external links', () => {
    it('renders website link with target=_blank and rel=noopener noreferrer', async () => {
        mockGet.mockResolvedValue({ data: makeVenue() });
        render(<Wrapper><VenueDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Национална галерия' }));
        const link = screen.getByRole('link', { name: /visit venue website/i });
        expect(link).toHaveAttribute('href', 'https://nationalgallery.bg');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    });

    it('omits website link when website_url is null', async () => {
        mockGet.mockResolvedValue({ data: makeVenue({ website_url: null }) });
        render(<Wrapper><VenueDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Национална галерия' }));
        expect(screen.queryByRole('link', { name: /visit venue website/i })).not.toBeInTheDocument();
    });

    it('renders map link when lat/lng are present', async () => {
        mockGet.mockResolvedValue({ data: makeVenue({ lat: 42.697, lng: 23.332 }) });
        render(<Wrapper><VenueDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Национална галерия' }));
        const mapLink = screen.getByRole('link', { name: /view on map/i });
        expect(mapLink).toHaveAttribute('href', expect.stringContaining('openstreetmap.org'));
        expect(mapLink).toHaveAttribute('href', expect.stringContaining('42.697'));
        expect(mapLink).toHaveAttribute('target', '_blank');
    });

    it('omits map link when lat/lng are null', async () => {
        mockGet.mockResolvedValue({ data: makeVenue({ lat: null, lng: null }) });
        render(<Wrapper><VenueDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Национална галерия' }));
        expect(screen.queryByRole('link', { name: /view on map/i })).not.toBeInTheDocument();
    });
});

// ── 4. Artworks section ───────────────────────────────────────────────────────

describe('VenueDetailPage — artworks', () => {
    it('shows empty artworks state when no artworks', async () => {
        mockGet.mockResolvedValue({ data: makeVenue({ artworks: [] }) });
        render(<Wrapper><VenueDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Национална галерия' }));
        expect(screen.getByText(/no artworks listed/i)).toBeInTheDocument();
    });

    it('renders artwork title as a link to /artworks/:slug', async () => {
        mockGet.mockResolvedValue({ data: makeVenue({ artworks: [makeVenueArtwork()] }) });
        render(<Wrapper><VenueDetailPage /></Wrapper>);
        await waitFor(() => screen.getByText('Изгрев'));
        const link = screen.getByRole('link', { name: 'Изгрев' });
        expect(link).toHaveAttribute('href', '/artworks/izgrev');
    });

    it('shows artist name', async () => {
        mockGet.mockResolvedValue({ data: makeVenue({ artworks: [makeVenueArtwork()] }) });
        render(<Wrapper><VenueDetailPage /></Wrapper>);
        await waitFor(() => screen.getByText('Изгрев'));
        expect(screen.getByText('Иван Петров')).toBeInTheDocument();
    });

    it('shows medium', async () => {
        mockGet.mockResolvedValue({ data: makeVenue({ artworks: [makeVenueArtwork()] }) });
        render(<Wrapper><VenueDetailPage /></Wrapper>);
        await waitFor(() => screen.getByText('Изгрев'));
        expect(screen.getByText(/· oil/i)).toBeInTheDocument();
    });

    it('shows location_label', async () => {
        mockGet.mockResolvedValue({ data: makeVenue({ artworks: [makeVenueArtwork()] }) });
        render(<Wrapper><VenueDetailPage /></Wrapper>);
        await waitFor(() => screen.getByText('Изгрев'));
        expect(screen.getByText(/зала 3/i)).toBeInTheDocument();
    });

    it('omits location_label when null', async () => {
        mockGet.mockResolvedValue({ data: makeVenue({ artworks: [makeVenueArtwork({ location_label: null })] }) });
        render(<Wrapper><VenueDetailPage /></Wrapper>);
        await waitFor(() => screen.getByText('Изгрев'));
        expect(screen.queryByText(/зала 3/i)).not.toBeInTheDocument();
    });

    it('shows IIIF viewer link when iiif_manifest_url is present', async () => {
        const manifestUrl = 'https://iiif.example.com/manifest.json';
        mockGet.mockResolvedValue({ data: makeVenue({ artworks: [makeVenueArtwork({ iiif_manifest_url: manifestUrl })] }) });
        render(<Wrapper><VenueDetailPage /></Wrapper>);
        await waitFor(() => screen.getByText('Изгрев'));
        const iiifLink = screen.getByRole('link', { name: /view iiif/i });
        expect(iiifLink).toHaveAttribute('href', expect.stringContaining(encodeURIComponent(manifestUrl)));
        expect(iiifLink).toHaveAttribute('target', '_blank');
    });

    it('omits IIIF link when iiif_manifest_url is null', async () => {
        mockGet.mockResolvedValue({ data: makeVenue({ artworks: [makeVenueArtwork({ iiif_manifest_url: null })] }) });
        render(<Wrapper><VenueDetailPage /></Wrapper>);
        await waitFor(() => screen.getByText('Изгрев'));
        expect(screen.queryByRole('link', { name: /view iiif/i })).not.toBeInTheDocument();
    });
});
