/**
 * Integration tests for ArtworkCreatePage.
 *
 * HTTP mocked via vi.mock('@/lib/api'). Navigation mocked via vi.mock('react-router-dom').
 * Tests cover: initial form render, field interactions, is_original toggle,
 * submit calls API with correct payload, navigate on success, error message,
 * button disabled while in-flight, breadcrumb nav link.
 */

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import ArtworkCreatePage from './ArtworkCreatePage';

// ── Fakes ─────────────────────────────────────────────────────────────────────

const { mockPost, mockNavigate } = vi.hoisted(() => ({
    mockPost:     vi.fn(),
    mockNavigate: vi.fn(),
}));

vi.mock('@/lib/api', async (importOriginal) => {
    const mod = await importOriginal<typeof import('@/lib/api')>();
    return { ...mod, api: { post: mockPost } };
});

vi.mock('react-router-dom', async (importOriginal) => {
    const mod = await importOriginal<typeof import('react-router-dom')>();
    return { ...mod, useNavigate: () => mockNavigate };
});

// ── Wrapper ───────────────────────────────────────────────────────────────────

function makeQueryClient() {
    return new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
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

beforeEach(() => {
    vi.clearAllMocks();
});

// ── 1. Initial render ─────────────────────────────────────────────────────────

describe('ArtworkCreatePage — initial render', () => {
    it('renders the page heading', () => {
        render(<Wrapper><ArtworkCreatePage /></Wrapper>);
        expect(screen.getByRole('heading', { name: /add artwork/i })).toBeInTheDocument();
    });

    it('renders breadcrumb link to /dashboard', () => {
        render(<Wrapper><ArtworkCreatePage /></Wrapper>);
        expect(screen.getByRole('link', { name: /dashboard/i })).toHaveAttribute('href', '/dashboard');
    });

    it('Title field is empty by default', () => {
        render(<Wrapper><ArtworkCreatePage /></Wrapper>);
        expect(screen.getByLabelText(/title/i)).toHaveValue('');
    });

    it('Save artwork button is present', () => {
        render(<Wrapper><ArtworkCreatePage /></Wrapper>);
        expect(screen.getByRole('button', { name: /save artwork/i })).toBeInTheDocument();
    });

    it('is_original checkbox is checked by default', () => {
        render(<Wrapper><ArtworkCreatePage /></Wrapper>);
        expect(screen.getByRole('checkbox', { name: /original work/i })).toBeChecked();
    });

    it('edition fields are hidden when is_original is true', () => {
        render(<Wrapper><ArtworkCreatePage /></Wrapper>);
        expect(screen.queryByLabelText(/edition number/i)).not.toBeInTheDocument();
        expect(screen.queryByLabelText(/total in edition/i)).not.toBeInTheDocument();
    });
});

// ── 2. is_original toggle ─────────────────────────────────────────────────────

describe('ArtworkCreatePage — is_original toggle', () => {
    it('shows edition fields when is_original is unchecked', () => {
        render(<Wrapper><ArtworkCreatePage /></Wrapper>);
        fireEvent.click(screen.getByRole('checkbox', { name: /original work/i }));
        expect(screen.getByLabelText(/edition number/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/total in edition/i)).toBeInTheDocument();
    });

    it('hides edition fields again when is_original is re-checked', () => {
        render(<Wrapper><ArtworkCreatePage /></Wrapper>);
        fireEvent.click(screen.getByRole('checkbox', { name: /original work/i }));
        fireEvent.click(screen.getByRole('checkbox', { name: /original work/i }));
        expect(screen.queryByLabelText(/edition number/i)).not.toBeInTheDocument();
    });
});

// ── 3. Submit ─────────────────────────────────────────────────────────────────

describe('ArtworkCreatePage — submit', () => {
    it('calls api.post with correct payload on submit', async () => {
        mockPost.mockResolvedValue({ data: { slug: 'new-work' } });
        render(<Wrapper><ArtworkCreatePage /></Wrapper>);

        fireEvent.change(screen.getByLabelText(/title/i), { target: { value: 'Нова творба' } });
        fireEvent.click(screen.getByRole('button', { name: /save artwork/i }));

        await waitFor(() => expect(mockPost).toHaveBeenCalledTimes(1));
        const [url, payload] = mockPost.mock.calls[0];
        expect(url).toBe('/artworks');
        expect(payload.title).toBe('Нова творба');
        expect(payload.is_original).toBe(true);
    });

    it('navigates to artwork detail page on success', async () => {
        mockPost.mockResolvedValue({ data: { slug: 'new-work' } });
        render(<Wrapper><ArtworkCreatePage /></Wrapper>);

        fireEvent.change(screen.getByLabelText(/title/i), { target: { value: 'Test' } });
        fireEvent.click(screen.getByRole('button', { name: /save artwork/i }));

        await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/artworks/new-work'));
    });

    it('shows error message on API failure', async () => {
        mockPost.mockRejectedValue(new Error('Network error'));
        render(<Wrapper><ArtworkCreatePage /></Wrapper>);

        fireEvent.change(screen.getByLabelText(/title/i), { target: { value: 'Test' } });
        fireEvent.click(screen.getByRole('button', { name: /save artwork/i }));

        await waitFor(() => expect(screen.getByText(/failed to save artwork/i)).toBeInTheDocument());
    });

    it('button is disabled while submit is in-flight', async () => {
        mockPost.mockReturnValue(new Promise(() => {}));
        render(<Wrapper><ArtworkCreatePage /></Wrapper>);

        fireEvent.change(screen.getByLabelText(/title/i), { target: { value: 'Test' } });
        fireEvent.click(screen.getByRole('button', { name: /save artwork/i }));

        await waitFor(() => expect(screen.getByRole('button', { name: /save artwork/i })).toBeDisabled());
    });

    it('includes edition fields in payload when is_original is false', async () => {
        mockPost.mockResolvedValue({ data: { slug: 'edition-work' } });
        render(<Wrapper><ArtworkCreatePage /></Wrapper>);

        fireEvent.click(screen.getByRole('checkbox', { name: /original work/i }));
        fireEvent.change(screen.getByLabelText(/title/i), { target: { value: 'Edition Work' } });
        fireEvent.change(screen.getByLabelText(/edition number/i), { target: { value: '3' } });
        fireEvent.change(screen.getByLabelText(/total in edition/i), { target: { value: '10' } });
        fireEvent.click(screen.getByRole('button', { name: /save artwork/i }));

        await waitFor(() => expect(mockPost).toHaveBeenCalledTimes(1));
        const payload = mockPost.mock.calls[0][1];
        expect(payload.is_original).toBe(false);
        expect(payload.edition_number).toBe(3);
        expect(payload.edition_total).toBe(10);
    });
});
