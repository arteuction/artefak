import { useState, type FormEvent } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { loginWithSanctum } from '@/lib/auth';
import { useAuth } from '@/context/AuthContext';
import { Button, Input } from '@/components/ui';

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
                <Input
                    label="Email"
                    type="email"
                    required
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    autoComplete="email"
                />
                <Input
                    label="Password"
                    type="password"
                    required
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    autoComplete="current-password"
                />

                {error && <p className="text-sm text-red-600">{error}</p>}

                <Button type="submit" loading={loading} className="w-full">
                    Sign in
                </Button>
            </form>

            <p className="mt-6 text-center text-sm text-[var(--color-text-muted)]">
                Don&apos;t have an account?{' '}
                <Link to="/register" className="text-[var(--color-accent)] hover:underline">
                    Register
                </Link>
            </p>
        </div>
    );
}
