/**
 * Integration tests for gallery ConsignmentListPage.
 *
 * HTTP mocked via vi.mock('@/lib/api'). Auth context injected via Wrapper.
 * Tests cover: unauthenticated state, loading, empty state, consignment list
 * rendering, status badges, artwork link, commission display, admin
 * approve/reject actions, non-admin hides action buttons, error message.
 */

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthContext } from '@/context/AuthContext';
import type { AuthUser } from '@/lib/auth';
import ConsignmentListPage from './ConsignmentListPage';

// ── Fake API ──────────────────────────────────────────────────────────────────

const { mockGet, mockPost } = vi.hoisted(() => ({
    mockGet:  vi.fn(),
    mockPost: vi.fn(),
}));

vi.mock('@/lib/api', async (importOriginal) => {
    const mod = await importOriginal<typeof import('@/lib/api')>();
    return { ...mod, api: { get: mockGet, post: mockPost } };
});

// ── Helpers ───────────────────────────────────────────────────────────────────

const ADMIN:  AuthUser = { id: 1, name: 'Admin', email: 'admin@test.com', role: 'admin' };
const ARTIST: AuthUser = { id: 2, name: 'Artist', email: 'artist@test.com', role: 'artist' };

function makeQueryClient() {
    return new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
}

function Wrapper({ user = ADMIN as AuthUser | null, children }: {
    user?: AuthUser | null;
    children: React.ReactNode;
}) {
    const ctx = { user, loading: false, refresh: vi.fn(), logout: vi.fn() };
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

function makeConsignment(overrides: Record<string, unknown> = {}) {
    return {
        id: 1,
        artwork_id: 10,
        gallery_id: 5,
        status: 'pending',
        commission_bps: 1500,
        notes: null,
        created_at: '2026-01-01T00:00:00Z',
        artwork: {
            id: 10,
            title: 'Балкански пейзаж',
            slug: 'balkanski-peyazh',
            status: 'listed',
            medium: 'painting',
        },
        ...overrides,
    };
}

function makePaginatedResponse(items: unknown[]) {
    return { data: { data: items, meta: { current_page: 1, last_page: 1, per_page: 15, total: items.length } } };
}

beforeEach(() => {
    vi.clearAllMocks();
});

// ── 1. Auth guard ─────────────────────────────────────────────────────────────

describe('ConsignmentListPage — auth guard', () => {
    it('shows sign-in prompt when unauthenticated', () => {
        render(<Wrapper user={null}><ConsignmentListPage /></Wrapper>);
        expect(screen.getByText(/sign in to view consignments/i)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /sign in/i })).toBeInTheDocument();
    });

    it('does not call API when unauthenticated', () => {
        render(<Wrapper user={null}><ConsignmentListPage /></Wrapper>);
        expect(mockGet).not.toHaveBeenCalled();
    });
});

// ── 2. Loading ────────────────────────────────────────────────────────────────

describe('ConsignmentListPage — loading', () => {
    it('shows spinner while data loads', () => {
        mockGet.mockReturnValue(new Promise(() => {}));
        render(<Wrapper><ConsignmentListPage /></Wrapper>);
        expect(screen.getByRole('status')).toBeInTheDocument();
    });
});

// ── 3. Empty state ────────────────────────────────────────────────────────────

describe('ConsignmentListPage — empty state', () => {
    it('shows empty state when there are no consignments', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([]));
        render(<Wrapper><ConsignmentListPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText(/no consignments/i)).toBeInTheDocument());
    });

    it('renders the page heading', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([]));
        render(<Wrapper><ConsignmentListPage /></Wrapper>);
        await waitFor(() => screen.getByText(/no consignments/i));
        expect(screen.getByRole('heading', { name: /consignments/i })).toBeInTheDocument();
    });
});

// ── 4. Consignment list ───────────────────────────────────────────────────────

describe('ConsignmentListPage — list rendering', () => {
    it('renders artwork title as a link', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeConsignment()]));
        render(<Wrapper><ConsignmentListPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText('Балкански пейзаж')).toBeInTheDocument());
        const link = screen.getByRole('link', { name: 'Балкански пейзаж' });
        expect(link).toHaveAttribute('href', '/artworks/balkanski-peyazh');
    });

    it('shows commission percentage', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeConsignment()]));
        render(<Wrapper><ConsignmentListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Балкански пейзаж'));
        expect(screen.getByText(/commission: 15%/i)).toBeInTheDocument();
    });

    it('shows artwork medium', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeConsignment()]));
        render(<Wrapper><ConsignmentListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Балкански пейзаж'));
        expect(screen.getByText('painting')).toBeInTheDocument();
    });

    it('shows notes when present', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeConsignment({ notes: 'Handle with care.' })]));
        render(<Wrapper><ConsignmentListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Балкански пейзаж'));
        expect(screen.getByText('Handle with care.')).toBeInTheDocument();
    });

    it('falls back to Artwork #id when artwork relation missing', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeConsignment({ artwork: undefined })]));
        render(<Wrapper><ConsignmentListPage /></Wrapper>);
        await waitFor(() => screen.getByText(/artwork #10/i));
    });
});

// ── 5. Status badges ──────────────────────────────────────────────────────────

describe('ConsignmentListPage — status badges', () => {
    it.each([
        ['pending',   'Pending review'],
        ['active',    'Active'],
        ['completed', 'Completed'],
        ['rejected',  'Rejected'],
    ])('shows "%s" badge label as "%s"', async (status, label) => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeConsignment({ status })]));
        render(<Wrapper><ConsignmentListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Балкански пейзаж'));
        expect(screen.getByText(label)).toBeInTheDocument();
    });
});

// ── 6. Admin approve / reject ─────────────────────────────────────────────────

describe('ConsignmentListPage — admin actions', () => {
    it('shows Approve and Reject buttons for pending consignment (admin)', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeConsignment({ status: 'pending' })]));
        render(<Wrapper user={ADMIN}><ConsignmentListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Балкански пейзаж'));
        expect(screen.getByRole('button', { name: /approve/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /reject/i })).toBeInTheDocument();
    });

    it('clicking Approve calls POST /consignments/:id/approve', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeConsignment({ id: 7, status: 'pending' })]));
        mockPost.mockResolvedValue({ data: {} });
        render(<Wrapper user={ADMIN}><ConsignmentListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Балкански пейзаж'));

        fireEvent.click(screen.getByRole('button', { name: /approve/i }));
        await waitFor(() => expect(mockPost).toHaveBeenCalledWith('/consignments/7/approve'));
    });

    it('clicking Reject calls POST /consignments/:id/terminate', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeConsignment({ id: 7, status: 'pending' })]));
        mockPost.mockResolvedValue({ data: {} });
        render(<Wrapper user={ADMIN}><ConsignmentListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Балкански пейзаж'));

        fireEvent.click(screen.getByRole('button', { name: /reject/i }));
        await waitFor(() => expect(mockPost).toHaveBeenCalledWith('/consignments/7/terminate'));
    });

    it('does not show Approve/Reject buttons for non-admin user', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeConsignment({ status: 'pending' })]));
        render(<Wrapper user={ARTIST}><ConsignmentListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Балкански пейзаж'));
        expect(screen.queryByRole('button', { name: /approve/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /reject/i })).not.toBeInTheDocument();
    });

    it('does not show action buttons for non-pending consignment', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeConsignment({ status: 'active' })]));
        render(<Wrapper user={ADMIN}><ConsignmentListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Балкански пейзаж'));
        expect(screen.queryByRole('button', { name: /approve/i })).not.toBeInTheDocument();
    });

    it('shows error message when approve fails', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeConsignment({ status: 'pending' })]));
        mockPost.mockRejectedValue(new Error('Server error'));
        render(<Wrapper user={ADMIN}><ConsignmentListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Балкански пейзаж'));

        fireEvent.click(screen.getByRole('button', { name: /approve/i }));
        await waitFor(() => expect(screen.getByText(/action failed/i)).toBeInTheDocument());
    });
});
