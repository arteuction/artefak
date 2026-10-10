import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { loginWithSanctum } from '@/lib/auth';
import { useAuth } from '@/context/AuthContext';

export default function LoginPage() {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const navigate = useNavigate();
    const { refresh } = useAuth();

    const handleSubmit = async (e: FormEvent) => {
        e.preventDefault();
        setError('');
        setLoading(true);
        try {
            await loginWithSanctum(email, password);
            await refresh();
            navigate('/');
        } catch {
            setError('Invalid credentials. Please try again.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="max-w-sm mx-auto py-16">
            <h1 className="text-2xl font-semibold mb-6 text-center">Sign in</h1>

            <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                    <label className="block text-sm font-medium mb-1">Email</label>
                    <input
                        type="email"
                        required
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-black"
                    />
                </div>
                <div>
                    <label className="block text-sm font-medium mb-1">Password</label>
                    <input
                        type="password"
                        required
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-black"
                    />
                </div>

                {error && <p className="text-sm text-red-600">{error}</p>}

                <button
                    type="submit"
                    disabled={loading}
                    className="w-full rounded-md bg-black text-white py-2 text-sm font-medium disabled:opacity-40 hover:bg-gray-800"
                >
                    {loading ? 'Signing in…' : 'Sign in'}
                </button>
            </form>
        </div>
    );
}
