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

    return (
        <div className="min-h-screen bg-white text-gray-900">
            <header className="border-b border-gray-200 px-6 py-4">
                <div className="mx-auto max-w-7xl flex items-center justify-between">
                    <Link to="/" className="text-2xl font-semibold tracking-tight">
                        ARTeuCtion
                    </Link>
                    <nav className="flex items-center gap-6 text-sm font-medium">
                        <NavLink
                            to="/artworks"
                            className={({ isActive }) =>
                                isActive ? 'text-black' : 'text-gray-500 hover:text-black'
                            }
                        >
                            Artworks
                        </NavLink>
                        <NavLink
                            to="/auctions"
                            className={({ isActive }) =>
                                isActive ? 'text-black' : 'text-gray-500 hover:text-black'
                            }
                        >
                            Auctions
                        </NavLink>
                        {user?.role === 'artist' && (
                            <NavLink
                                to="/dashboard"
                                className={({ isActive }) =>
                                    isActive ? 'text-black' : 'text-gray-500 hover:text-black'
                                }
                            >
                                My works
                            </NavLink>
                        )}
                        {user ? (
                            <button
                                onClick={handleLogout}
                                className="text-gray-500 hover:text-black"
                            >
                                Sign out
                            </button>
                        ) : (
                            <NavLink
                                to="/login"
                                className={({ isActive }) =>
                                    isActive ? 'text-black' : 'text-gray-500 hover:text-black'
                                }
                            >
                                Sign in
                            </NavLink>
                        )}
                    </nav>
                </div>
            </header>

            <main className="mx-auto max-w-7xl px-6 py-8">
                <Outlet />
            </main>

            <footer className="border-t border-gray-200 px-6 py-6 text-center text-xs text-gray-400">
                &copy; {new Date().getFullYear()} ARTeuCtion
            </footer>
        </div>
    );
}
