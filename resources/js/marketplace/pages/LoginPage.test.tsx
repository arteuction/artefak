/**
 * Integration tests for LoginPage.
 *
 * loginWithSanctum is mocked. AuthContext.refresh and react-router navigate
 * are observed to verify the post-login redirect flow.
 */

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import { AuthContext } from '@/context/AuthContext';
import LoginPage from './LoginPage';

// ── Mocks ─────────────────────────────────────────────────────────────────────

const { mockLoginWithSanctum } = vi.hoisted(() => ({
    mockLoginWithSanctum: vi.fn(),
}));

vi.mock('@/lib/auth', async (importOriginal) => {
    const mod = await importOriginal<typeof import('@/lib/auth')>();
    return { ...mod, loginWithSanctum: mockLoginWithSanctum };
});

// Capture navigate calls via react-router mock
const mockNavigate = vi.fn();
vi.mock('react-router-dom', async (importOriginal) => {
    const mod = await importOriginal<typeof import('react-router-dom')>();
    return { ...mod, useNavigate: () => mockNavigate };
});

// ── Wrapper ───────────────────────────────────────────────────────────────────

const mockRefresh = vi.fn();

function Wrapper({ children }: { children: React.ReactNode }) {
    return (
        <AuthContext.Provider value={{ user: null, loading: false, refresh: mockRefresh }}>
            <MemoryRouter initialEntries={['/login']}>
                {children}
            </MemoryRouter>
        </AuthContext.Provider>
    );
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
    vi.clearAllMocks();
});

// ── 1. Rendering ──────────────────────────────────────────────────────────────

describe('LoginPage — rendering', () => {
    it('renders Sign in heading', () => {
        render(<Wrapper><LoginPage /></Wrapper>);
        expect(screen.getByRole('heading', { name: /sign in/i })).toBeInTheDocument();
    });

    it('renders email and password inputs', () => {
        render(<Wrapper><LoginPage /></Wrapper>);
        expect(screen.getByLabelText('Email')).toBeInTheDocument();
        expect(screen.getByLabelText('Password')).toBeInTheDocument();
    });

    it('renders submit button', () => {
        render(<Wrapper><LoginPage /></Wrapper>);
        expect(screen.getByRole('button', { name: /sign in/i })).toBeInTheDocument();
    });

    it('renders a Register link', () => {
        render(<Wrapper><LoginPage /></Wrapper>);
        expect(screen.getByRole('link', { name: /register/i })).toBeInTheDocument();
    });
});

// ── 2. Successful login flow ──────────────────────────────────────────────────

describe('LoginPage — successful login', () => {
    it('calls loginWithSanctum with entered credentials', async () => {
        mockLoginWithSanctum.mockResolvedValue(undefined);
        mockRefresh.mockResolvedValue(undefined);

        render(<Wrapper><LoginPage /></Wrapper>);
        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'buyer@test.com' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret123' } });
        fireEvent.click(screen.getByRole('button', { name: /sign in/i }));

        await waitFor(() => expect(mockLoginWithSanctum).toHaveBeenCalledWith('buyer@test.com', 'secret123'));
    });

    it('calls refresh after successful login', async () => {
        mockLoginWithSanctum.mockResolvedValue(undefined);
        mockRefresh.mockResolvedValue(undefined);

        render(<Wrapper><LoginPage /></Wrapper>);
        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'buyer@test.com' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret123' } });
        fireEvent.click(screen.getByRole('button', { name: /sign in/i }));

        await waitFor(() => expect(mockRefresh).toHaveBeenCalled());
    });

    it('navigates to / after successful login', async () => {
        mockLoginWithSanctum.mockResolvedValue(undefined);
        mockRefresh.mockResolvedValue(undefined);

        render(<Wrapper><LoginPage /></Wrapper>);
        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'buyer@test.com' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret123' } });
        fireEvent.click(screen.getByRole('button', { name: /sign in/i }));

        await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/'));
    });
});

// ── 3. Failed login flow ──────────────────────────────────────────────────────

describe('LoginPage — failed login', () => {
    it('shows error message on invalid credentials', async () => {
        mockLoginWithSanctum.mockRejectedValue(new Error('Unauthorized'));

        render(<Wrapper><LoginPage /></Wrapper>);
        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'wrong@test.com' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'wrongpass' } });
        fireEvent.click(screen.getByRole('button', { name: /sign in/i }));

        await waitFor(() => expect(screen.getByText(/invalid credentials/i)).toBeInTheDocument());
    });

    it('does not redirect on failed login', async () => {
        mockLoginWithSanctum.mockRejectedValue(new Error('Unauthorized'));

        render(<Wrapper><LoginPage /></Wrapper>);
        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'wrong@test.com' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'wrongpass' } });
        fireEvent.click(screen.getByRole('button', { name: /sign in/i }));

        await waitFor(() => screen.getByText(/invalid credentials/i));
        expect(mockNavigate).not.toHaveBeenCalled();
    });

    it('clears error when user starts a new submission', async () => {
        mockLoginWithSanctum
            .mockRejectedValueOnce(new Error('Unauthorized'))
            .mockResolvedValue(undefined);
        mockRefresh.mockResolvedValue(undefined);

        render(<Wrapper><LoginPage /></Wrapper>);
        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'wrong@test.com' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'wrongpass' } });
        fireEvent.click(screen.getByRole('button', { name: /sign in/i }));
        await waitFor(() => screen.getByText(/invalid credentials/i));

        // Submit again with correct password
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'correct' } });
        fireEvent.click(screen.getByRole('button', { name: /sign in/i }));

        await waitFor(() => expect(screen.queryByText(/invalid credentials/i)).not.toBeInTheDocument());
    });
});

// ── 4. Loading state ──────────────────────────────────────────────────────────

describe('LoginPage — loading state', () => {
    it('submit button is disabled while login is in-flight', async () => {
        let resolve!: () => void;
        mockLoginWithSanctum.mockReturnValue(new Promise(r => { resolve = r; }));

        render(<Wrapper><LoginPage /></Wrapper>);
        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'b@test.com' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'pw' } });
        fireEvent.click(screen.getByRole('button', { name: /sign in/i }));

        await waitFor(() => expect(screen.getByRole('button', { name: /sign in/i })).toBeDisabled());

        // Resolve to clean up
        resolve();
    });
});
