import { useEffect } from 'react';
import { useSearchParams, useNavigate, Link } from 'react-router-dom';
import axios from 'axios';
import { useQuery } from '@tanstack/react-query';
import type { VenueArtwork } from '@/lib/api';
import { Spinner } from '@/components/ui';

export default function QrScanPage() {
    const [params] = useSearchParams();
    const navigate = useNavigate();
    const token = params.get('token');

    const { data, isLoading, isError } = useQuery({
        queryKey: ['qr-scan', token],
        queryFn: async () => {
            const res = await axios.get<VenueArtwork>(`/api/artifacts/${token}`, {
                headers: { Accept: 'application/json' },
                withCredentials: true,
            });
            return res.data;
        },
        enabled: !!token,
        retry: false,
    });

    useEffect(() => {
        if (data?.artwork?.slug) {
            navigate(`/artworks/${data.artwork.slug}`, { replace: true });
        }
    }, [data, navigate]);

    if (!token) {
        return (
            <div className="text-center py-16">
                <p className="text-[var(--color-text-muted)] mb-4">No QR token found in the URL.</p>
                <Link
                    to="/metro"
                    className="text-sm text-[var(--color-accent)] hover:underline"
                >
                    Browse venues →
                </Link>
            </div>
        );
    }

    if (isLoading) {
        return (
            <div className="flex flex-col items-center gap-4 py-24">
                <Spinner size="lg" />
                <p className="text-sm text-[var(--color-text-muted)]">Looking up artwork…</p>
            </div>
        );
    }

    if (isError || !data) {
        return (
            <div className="text-center py-16">
                <p className="text-[var(--color-text-muted)] mb-4">This QR code could not be resolved.</p>
                <Link
                    to="/metro"
                    className="text-sm text-[var(--color-accent)] hover:underline"
                >
                    Browse venues →
                </Link>
            </div>
        );
    }

    // Redirecting — show brief transition state
    return (
        <div className="flex flex-col items-center gap-4 py-24">
            <Spinner size="lg" />
            <p className="text-sm text-[var(--color-text-muted)]">
                Redirecting to {data.artwork.title}…
            </p>
        </div>
    );
}
