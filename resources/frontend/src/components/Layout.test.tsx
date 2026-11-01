/**
 * Phase 177 — Layout component tests.
 *
 * Tests: brand link, nav links always present, role-conditional nav links,
 * user name display when logged in, sign-out button, sign-in link when logged out,
 * footer copyright, Outlet renders child route content.
 */

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { vi, describe, it, expect, beforeEach } from 'vitest';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { AuthContext } from '@/context/AuthContext';
import type { AuthUser } from '@/lib/auth';
import Layout from './Layout';

// ── Mocks ─────────────────────────────────────────────────────────────────────

const mockNavigate = vi.fn();
vi.mock('react-router-dom', async (importOriginal) => {
    const mod = await importOriginal<typeof import('react-router-dom')>();
    return {
        ...mod,
        useNavigate: () => mockNavigate,
    };
});

const mockLogout = vi.fn().mockResolvedValue(undefined);
vi.mock('@/lib/auth', () => ({ logout: () => mockLogout() }));

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeUser(role: NonNullable<AuthUser['role']> = 'buyer'): AuthUser {
    return { id: 1, name: 'Иван Петров', email: 'ivan@example.com', role };
}

function renderLayout(
    user: AuthUser | null = null,
    refresh: () => Promise<void> = async () => {},
) {
    return render(
        <AuthContext.Provider value={{ user, loading: false, refresh }}>
            <MemoryRouter initialEntries={['/']}>
                <Routes>
                    <Route element={<Layout />}>
                        <Route index element={<div>Home content</div>} />
                    </Route>
                </Routes>
            </MemoryRouter>
        </AuthContext.Provider>
    );
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('Layout — structure', () => {
    it('renders the brand name "ARTeuCtion"', () => {
        renderLayout();
        expect(screen.getByText('ARTeuCtion')).toBeInTheDocument();
    });

    it('renders Artworks nav link', () => {
        renderLayout();
        expect(screen.getByRole('link', { name: 'Artworks' })).toBeInTheDocument();
    });

    it('renders Auctions nav link', () => {
        renderLayout();
        expect(screen.getByRole('link', { name: 'Auctions' })).toBeInTheDocument();
    });

    it('renders Library nav link', () => {
        renderLayout();
        expect(screen.getByRole('link', { name: 'Library' })).toBeInTheDocument();
    });

    it('renders ArtMetro nav link', () => {
        renderLayout();
        expect(screen.getByRole('link', { name: 'ArtMetro' })).toBeInTheDocument();
    });

    it('renders Outlet child content in main', () => {
        renderLayout();
        expect(screen.getByText('Home content')).toBeInTheDocument();
    });

    it('renders footer with copyright text', () => {
        renderLayout();
        const footer = document.querySelector('footer');
        expect(footer?.textContent).toContain('ARTeuCtion');
    });
});

describe('Layout — logged out', () => {
    it('shows Sign in link when user is null', () => {
        renderLayout(null);
        expect(screen.getByRole('link', { name: 'Sign in' })).toBeInTheDocument();
    });

    it('does not show Sign out button when user is null', () => {
        renderLayout(null);
        expect(screen.queryByRole('button', { name: 'Sign out' })).toBeNull();
    });

    it('does not show artist-only My works link', () => {
        renderLayout(null);
        expect(screen.queryByRole('link', { name: 'My works' })).toBeNull();
    });
});

describe('Layout — logged in as artist', () => {
    it('shows user name', () => {
        renderLayout(makeUser('artist'));
        expect(screen.getByText('Иван Петров')).toBeInTheDocument();
    });

    it('shows My works link for artist role', () => {
        renderLayout(makeUser('artist'));
        expect(screen.getByRole('link', { name: 'My works' })).toBeInTheDocument();
    });

    it('does not show My offers link for artist role', () => {
        renderLayout(makeUser('artist'));
        expect(screen.queryByRole('link', { name: 'My offers' })).toBeNull();
    });

    it('shows Sign out button', () => {
        renderLayout(makeUser('artist'));
        expect(screen.getByRole('button', { name: 'Sign out' })).toBeInTheDocument();
    });
});

describe('Layout — logged in as buyer', () => {
    it('shows My offers link for buyer role', () => {
        renderLayout(makeUser('buyer'));
        expect(screen.getByRole('link', { name: 'My offers' })).toBeInTheDocument();
    });

    it('does not show My works link for buyer role', () => {
        renderLayout(makeUser('buyer'));
        expect(screen.queryByRole('link', { name: 'My works' })).toBeNull();
    });
});

describe('Layout — sign out', () => {
    beforeEach(() => {
        mockNavigate.mockClear();
        mockLogout.mockClear();
    });

    it('calls logout and navigates to / on sign out', async () => {
        const refresh = vi.fn().mockResolvedValue(undefined);
        renderLayout(makeUser('buyer'), refresh);

        await userEvent.click(screen.getByRole('button', { name: 'Sign out' }));

        expect(mockLogout).toHaveBeenCalledTimes(1);
        expect(refresh).toHaveBeenCalledTimes(1);
        expect(mockNavigate).toHaveBeenCalledWith('/');
    });
});
