import { Outlet, NavLink, Link } from 'react-router-dom';

export default function Layout() {
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
