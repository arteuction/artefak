/**
 * Phase 178 — AuthContext / AuthProvider / useAuth tests.
 *
 * Tests: initial loading state, resolves user after getAuthUser resolves,
 * user is null when getAuthUser returns null, refresh re-fetches user,
 * useAuth outside provider throws (or returns default), context value shape.
 */

import { render, screen, waitFor, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { vi, describe, it, expect, beforeEach } from 'vitest';
import { AuthProvider, useAuth } from './AuthContext';

// ── Mock ──────────────────────────────────────────────────────────────────────

const mockGetAuthUser = vi.fn();

vi.mock('@/lib/auth', async (importOriginal) => {
    const mod = await importOriginal<typeof import('@/lib/auth')>();
    return { ...mod, getAuthUser: () => mockGetAuthUser() };
});

// ── Helper ────────────────────────────────────────────────────────────────────

function TestConsumer() {
    const { user, loading, refresh } = useAuth();
    return (
        <div>
            <span data-testid="loading">{String(loading)}</span>
            <span data-testid="user">{user ? user.name : 'null'}</span>
            <button onClick={() => void refresh()}>Refresh</button>
        </div>
    );
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('AuthProvider', () => {
    beforeEach(() => {
        mockGetAuthUser.mockClear();
    });

    it('starts with loading=true', () => {
        // Delay resolution so we can observe the initial state
        mockGetAuthUser.mockReturnValue(new Promise(() => {}));

        render(
            <AuthProvider>
                <TestConsumer />
            </AuthProvider>
        );

        expect(screen.getByTestId('loading').textContent).toBe('true');
    });

    it('sets loading=false after getAuthUser resolves', async () => {
        mockGetAuthUser.mockResolvedValue(null);

        render(
            <AuthProvider>
                <TestConsumer />
            </AuthProvider>
        );

        await waitFor(() =>
            expect(screen.getByTestId('loading').textContent).toBe('false')
        );
    });

    it('sets user when getAuthUser returns a user', async () => {
        mockGetAuthUser.mockResolvedValue({
            id: 1, name: 'Иван Петров', email: 'ivan@example.com', role: 'artist',
        });

        render(
            <AuthProvider>
                <TestConsumer />
            </AuthProvider>
        );

        await waitFor(() =>
            expect(screen.getByTestId('user').textContent).toBe('Иван Петров')
        );
    });

    it('user remains null when getAuthUser returns null', async () => {
        mockGetAuthUser.mockResolvedValue(null);

        render(
            <AuthProvider>
                <TestConsumer />
            </AuthProvider>
        );

        await waitFor(() =>
            expect(screen.getByTestId('loading').textContent).toBe('false')
        );
        expect(screen.getByTestId('user').textContent).toBe('null');
    });

    it('refresh updates the user', async () => {
        mockGetAuthUser
            .mockResolvedValueOnce(null)
            .mockResolvedValueOnce({ id: 2, name: 'Maria', email: 'm@example.com', role: 'buyer' });

        render(
            <AuthProvider>
                <TestConsumer />
            </AuthProvider>
        );

        await waitFor(() =>
            expect(screen.getByTestId('loading').textContent).toBe('false')
        );
        expect(screen.getByTestId('user').textContent).toBe('null');

        await act(async () => {
            await userEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        });

        await waitFor(() =>
            expect(screen.getByTestId('user').textContent).toBe('Maria')
        );
    });

    it('refresh clears user when getAuthUser returns null on re-fetch', async () => {
        mockGetAuthUser
            .mockResolvedValueOnce({ id: 1, name: 'Ivan', email: 'i@example.com', role: 'artist' })
            .mockResolvedValueOnce(null);

        render(
            <AuthProvider>
                <TestConsumer />
            </AuthProvider>
        );

        await waitFor(() =>
            expect(screen.getByTestId('user').textContent).toBe('Ivan')
        );

        await act(async () => {
            await userEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        });

        await waitFor(() =>
            expect(screen.getByTestId('user').textContent).toBe('null')
        );
    });
});

describe('useAuth context default', () => {
    it('returns default context value (user=null, loading=true) outside provider', () => {
        function Outside() {
            const { user, loading } = useAuth();
            return (
                <div>
                    <span data-testid="loading">{String(loading)}</span>
                    <span data-testid="user">{String(user)}</span>
                </div>
            );
        }

        render(<Outside />);

        expect(screen.getByTestId('loading').textContent).toBe('true');
        expect(screen.getByTestId('user').textContent).toBe('null');
    });
});
