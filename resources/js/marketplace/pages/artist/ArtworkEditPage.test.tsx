/**
 * Integration tests for ArtworkEditPage.
 *
 * HTTP mocked via vi.mock('@/lib/api'). ArtworkImageUpload mocked to avoid
 * file-upload complexity. Tests cover: loading spinner, not-found, form
 * pre-populated from API, save changes (PATCH), success/error messages,
 * submit-for-review flow (confirm dialog, confirm button, error), readonly
 * state for non-draft artworks, breadcrumb.
 */

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach } from 'vitest';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import ArtworkEditPage from './ArtworkEditPage';

// ── Fakes ─────────────────────────────────────────────────────────────────────

const { mockGet, mockPost, mockPatch } = vi.hoisted(() => ({
    mockGet:   vi.fn(),
    mockPost:  vi.fn(),
    mockPatch: vi.fn(),
}));

vi.mock('@/lib/api', async (importOriginal) => {
    const mod = await importOriginal<typeof import('@/lib/api')>();
    return { ...mod, api: { get: mockGet, post: mockPost, patch: mockPatch } };
});

vi.mock('@/components/ArtworkImageUpload', () => ({
    default: () => <div data-testid="image-upload-mock" />,
}));

// ── Wrapper ───────────────────────────────────────────────────────────────────

function makeQueryClient() {
    return new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
}

function Wrapper({ slug = 'test-work', children }: { slug?: string; children: React.ReactNode }) {
    return (
        <MemoryRouter initialEntries={[`/dashboard/artworks/${slug}/edit`]}>
            <QueryClientProvider client={makeQueryClient()}>
                <Routes>
                    <Route path="/dashboard/artworks/:slug/edit" element={children} />
                </Routes>
            </QueryClientProvider>
        </MemoryRouter>
    );
}

function makeArtwork(overrides: Record<string, unknown> = {}) {
    return {
        id: 1,
        title: 'Изгрев над София',
        slug: 'test-work',
        status: 'draft',
        medium: 'painting',
        dimensions: '80 × 60 cm',
        year_created: 2024,
        description: 'Oil on canvas.',
        is_original: true,
        edition_number: null,
        edition_total: null,
        ...overrides,
    };
}

beforeEach(() => {
    vi.clearAllMocks();
});

// ── 1. Loading / not-found ────────────────────────────────────────────────────

describe('ArtworkEditPage — loading and not-found', () => {
    it('shows spinner while artwork loads', () => {
        mockGet.mockReturnValue(new Promise(() => {}));
        render(<Wrapper><ArtworkEditPage /></Wrapper>);
        expect(screen.getByRole('status')).toBeInTheDocument();
    });

    it('shows not-found message on API error', async () => {
        mockGet.mockRejectedValue(new Error('404'));
        render(<Wrapper><ArtworkEditPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText(/artwork not found/i)).toBeInTheDocument());
    });
});

// ── 2. Form pre-population ────────────────────────────────────────────────────

describe('ArtworkEditPage — form pre-population', () => {
    it('renders the artwork title as heading', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork() });
        render(<Wrapper><ArtworkEditPage /></Wrapper>);
        await waitFor(() => expect(screen.getByRole('heading', { name: 'Изгрев над София' })).toBeInTheDocument());
    });

    it('renders Draft status badge', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork() });
        render(<Wrapper><ArtworkEditPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изгрев над София' }));
        expect(screen.getByText('Draft')).toBeInTheDocument();
    });

    it('breadcrumb links to /dashboard', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork() });
        render(<Wrapper><ArtworkEditPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изгрев над София' }));
        expect(screen.getByRole('link', { name: /dashboard/i })).toHaveAttribute('href', '/dashboard');
    });

    it('shows image upload section for draft artworks', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork() });
        render(<Wrapper><ArtworkEditPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изгрев над София' }));
        expect(screen.getByTestId('image-upload-mock')).toBeInTheDocument();
    });

    it('does not show image upload for listed artwork', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork({ status: 'listed' }) });
        render(<Wrapper><ArtworkEditPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изгрев над София' }));
        expect(screen.queryByTestId('image-upload-mock')).not.toBeInTheDocument();
    });
});

// ── 3. Save changes ───────────────────────────────────────────────────────────

describe('ArtworkEditPage — save changes', () => {
    it('shows Saved message after successful PATCH', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork() });
        mockPatch.mockResolvedValue({ data: makeArtwork({ title: 'Updated' }) });
        render(<Wrapper><ArtworkEditPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изгрев над София' }));

        fireEvent.click(screen.getByRole('button', { name: /save changes/i }));
        await waitFor(() => expect(screen.getByText(/^saved\.$/i)).toBeInTheDocument());
    });

    it('shows error message when save fails', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork() });
        mockPatch.mockRejectedValue(new Error('Server error'));
        render(<Wrapper><ArtworkEditPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изгрев над София' }));

        fireEvent.click(screen.getByRole('button', { name: /save changes/i }));
        await waitFor(() => expect(screen.getByText(/failed to save/i)).toBeInTheDocument());
    });

    it('Save changes button is disabled while in-flight', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork() });
        mockPatch.mockReturnValue(new Promise(() => {}));
        render(<Wrapper><ArtworkEditPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изгрев над София' }));

        fireEvent.click(screen.getByRole('button', { name: /save changes/i }));
        await waitFor(() => expect(screen.getByRole('button', { name: /save changes/i })).toBeDisabled());
    });
});

// ── 4. Submit for review ──────────────────────────────────────────────────────

describe('ArtworkEditPage — submit for review', () => {
    it('shows Submit for review button for draft artwork', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork() });
        render(<Wrapper><ArtworkEditPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изгрев над София' }));
        expect(screen.getByRole('button', { name: /submit for review/i })).toBeInTheDocument();
    });

    it('clicking Submit for review shows confirmation dialog', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork() });
        render(<Wrapper><ArtworkEditPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изгрев над София' }));

        fireEvent.click(screen.getByRole('button', { name: /submit for review/i }));
        expect(screen.getByText(/submit this artwork for review/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /confirm submission/i })).toBeInTheDocument();
    });

    it('Cancel button in dialog hides the confirmation', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork() });
        render(<Wrapper><ArtworkEditPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изгрев над София' }));

        fireEvent.click(screen.getByRole('button', { name: /submit for review/i }));
        fireEvent.click(screen.getByRole('button', { name: /^cancel$/i }));
        expect(screen.queryByText(/submit this artwork for review/i)).not.toBeInTheDocument();
    });

    it('Confirm submission calls api.post /artworks/:slug/submit', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork() });
        mockPost.mockResolvedValue({ data: makeArtwork({ status: 'pending' }) });
        render(<Wrapper><ArtworkEditPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изгрев над София' }));

        fireEvent.click(screen.getByRole('button', { name: /submit for review/i }));
        fireEvent.click(screen.getByRole('button', { name: /confirm submission/i }));

        await waitFor(() => expect(mockPost).toHaveBeenCalledWith('/artworks/test-work/submit'));
    });

    it('shows submission error message when submit fails', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork() });
        mockPost.mockRejectedValue(new Error('Server error'));
        render(<Wrapper><ArtworkEditPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изгрев над София' }));

        fireEvent.click(screen.getByRole('button', { name: /submit for review/i }));
        fireEvent.click(screen.getByRole('button', { name: /confirm submission/i }));

        await waitFor(() => expect(screen.getByText(/submission failed/i)).toBeInTheDocument());
    });
});

// ── 5. Non-draft artwork ──────────────────────────────────────────────────────

describe('ArtworkEditPage — non-draft artworks', () => {
    it('does not show Submit for review for listed artwork', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork({ status: 'listed' }) });
        render(<Wrapper><ArtworkEditPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изгрев над София' }));
        expect(screen.queryByRole('button', { name: /submit for review/i })).not.toBeInTheDocument();
    });

    it('shows readonly message with link to public page for listed artwork', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork({ status: 'listed' }) });
        render(<Wrapper><ArtworkEditPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Изгрев над София' }));
        expect(screen.getByText(/this artwork is/i)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /view public page/i })).toHaveAttribute('href', '/artworks/test-work');
    });
});
