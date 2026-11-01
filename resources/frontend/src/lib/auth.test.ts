/**
 * Phase 179 — auth.ts function tests.
 *
 * Tests: loginWithSanctum fetches CSRF cookie then posts credentials,
 * loginWithSanctum propagates errors, logout posts to /logout,
 * getAuthUser returns user data on success, returns null on error.
 */

import { vi, describe, it, expect, beforeEach } from 'vitest';
import axios from 'axios';

// ── Mocks ─────────────────────────────────────────────────────────────────────

const mockApiGet  = vi.fn();
const mockApiPost = vi.fn();

vi.mock('./api', () => ({
    api: {
        get:  (...args: unknown[]) => mockApiGet(...args),
        post: (...args: unknown[]) => mockApiPost(...args),
    },
}));

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
    },
}));

const mockedAxios = vi.mocked(axios, true);

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('getAuthUser', () => {
    beforeEach(() => {
        mockApiGet.mockReset();
    });

    it('returns user data when /me responds with 200', async () => {
        const user = { id: 1, name: 'Иван', email: 'ivan@example.com', role: 'artist' };
        mockApiGet.mockResolvedValue({ data: user });

        const { getAuthUser } = await import('./auth');
        const result = await getAuthUser();

        expect(result).toEqual(user);
        expect(mockApiGet).toHaveBeenCalledWith('/me');
    });

    it('returns null when /me throws (unauthenticated)', async () => {
        mockApiGet.mockRejectedValue(new Error('401 Unauthorized'));

        const { getAuthUser } = await import('./auth');
        const result = await getAuthUser();

        expect(result).toBeNull();
    });
});

describe('loginWithSanctum', () => {
    beforeEach(() => {
        (mockedAxios.get as ReturnType<typeof vi.fn>).mockReset();
        mockApiPost.mockReset();
    });

    it('fetches the CSRF cookie before posting credentials', async () => {
        (mockedAxios.get as ReturnType<typeof vi.fn>).mockResolvedValue({});
        mockApiPost.mockResolvedValue({});

        const { loginWithSanctum } = await import('./auth');
        await loginWithSanctum('ivan@example.com', 's3cret');

        expect(mockedAxios.get).toHaveBeenCalledWith(
            '/sanctum/csrf-cookie',
            { withCredentials: true },
        );
    });

    it('posts email and password to /login after CSRF', async () => {
        (mockedAxios.get as ReturnType<typeof vi.fn>).mockResolvedValue({});
        mockApiPost.mockResolvedValue({});

        const { loginWithSanctum } = await import('./auth');
        await loginWithSanctum('ivan@example.com', 's3cret');

        expect(mockApiPost).toHaveBeenCalledWith('/login', {
            email:    'ivan@example.com',
            password: 's3cret',
        });
    });

    it('propagates errors from the login API call', async () => {
        (mockedAxios.get as ReturnType<typeof vi.fn>).mockResolvedValue({});
        mockApiPost.mockRejectedValue(new Error('422 Unprocessable'));

        const { loginWithSanctum } = await import('./auth');
        await expect(loginWithSanctum('bad@example.com', 'wrong')).rejects.toThrow('422 Unprocessable');
    });
});

describe('logout', () => {
    beforeEach(() => {
        mockApiPost.mockReset();
    });

    it('posts to /logout', async () => {
        mockApiPost.mockResolvedValue({});

        const { logout } = await import('./auth');
        await logout();

        expect(mockApiPost).toHaveBeenCalledWith('/logout');
    });

    it('propagates errors from the logout call', async () => {
        mockApiPost.mockRejectedValue(new Error('Network Error'));

        const { logout } = await import('./auth');
        await expect(logout()).rejects.toThrow('Network Error');
    });
});
