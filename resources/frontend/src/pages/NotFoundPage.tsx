import { Link } from 'react-router-dom';

export default function NotFoundPage() {
    return (
        <div className="text-center py-24">
            <p className="text-6xl font-light text-gray-200 mb-4">404</p>
            <h1 className="text-xl font-medium mb-2">Page not found</h1>
            <p className="text-sm text-gray-500 mb-6">The page you're looking for doesn't exist.</p>
            <Link to="/" className="rounded-md bg-black text-white px-5 py-2 text-sm font-medium hover:bg-gray-800">
                Go home
            </Link>
        </div>
    );
}
