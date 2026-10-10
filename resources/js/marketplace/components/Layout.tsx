import { Outlet, NavLink, Link, useNavigate } from 'react-router-dom';
import { useAuth } from '@/context/AuthContext';
import { logout } from '@/lib/auth';

export default function Layout() {
    const { user, refresh } = useAuth();
    const navigate = useNavigate();

    const handleLogout = async () => {
        await logout();
        await refresh();
        navigate('/');
    };

    const navLinkClass = ({ isActive }: { isActive: boolean }) =>
        isActive
            ? 'text-[var(--color-text)] font-medium'
            : 'text-[var(--color-text-muted)] hover:text-[var(--color-text)] transition-colors';

    return (
        <div className="min-h-screen bg-[var(--color-bg)] text-[var(--color-text)]">
            <header className="border-b border-[var(--color-border)] px-6 py-4 sticky top-0 bg-[var(--color-bg)] z-10">
                <div className="mx-auto max-w-7xl flex items-center justify-between gap-6">
                    <Link
                        to="/"
                        className="text-xl font-semibold tracking-tight shrink-0 hover:opacity-80 transition-opacity"
                    >
                        ARTeuCtion
                    </Link>

                    <nav className="flex items-center gap-5 text-sm">
                        <NavLink to="/artworks" className={navLinkClass}>Artworks</NavLink>
                        <NavLink to="/auctions" className={navLinkClass}>Auctions</NavLink>
                        {user?.role === 'artist' && (
                            <NavLink to="/dashboard" className={navLinkClass}>My works</NavLink>
                        )}
                        {user?.role === 'buyer' && (
                            <NavLink to="/offers" className={navLinkClass}>My offers</NavLink>
                        )}
                    </nav>

                    <div className="flex items-center gap-3 text-sm shrink-0">
                        {user ? (
                            <>
                                <span className="text-[var(--color-text-muted)] hidden sm:block truncate max-w-[160px]">
                                    {user.name}
                                </span>
                                <button
                                    onClick={handleLogout}
                                    className="text-[var(--color-text-muted)] hover:text-[var(--color-text)] transition-colors"
                                >
                                    Sign out
                                </button>
                            </>
                        ) : (
                            <NavLink
                                to="/login"
                                className="text-[var(--color-text-muted)] hover:text-[var(--color-text)] transition-colors"
                            >
                                Sign in
                            </NavLink>
                        )}
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-7xl px-6 py-8">
                <Outlet />
            </main>

            <footer className="border-t border-[var(--color-border)] px-6 py-6 text-center text-xs text-[var(--color-text-faint)]">
                &copy; {new Date().getFullYear()} ARTeuCtion
            </footer>
        </div>
    );
}
