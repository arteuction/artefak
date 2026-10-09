import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api } from '@/lib/api';
import type { Auction, PaginatedResponse } from '@/lib/api';

export default function AuctionListPage() {
    const { data, isLoading, isError } = useQuery({
        queryKey: ['auctions'],
        queryFn: async () => {
            const res = await api.get<PaginatedResponse<Auction>>('/auctions');
            return res.data;
        },
    });

    const statusColor: Record<string, string> = {
        live: 'bg-red-100 text-red-700',
        scheduled: 'bg-blue-100 text-blue-700',
        draft: 'bg-gray-100 text-gray-500',
        closed: 'bg-gray-100 text-gray-400',
        cancelled: 'bg-gray-100 text-gray-400',
    };

    return (
        <div>
            <h1 className="text-3xl font-semibold mb-6">Auctions</h1>

            {isLoading && (
                <div className="space-y-3">
                    {Array.from({ length: 4 }).map((_, i) => (
                        <div key={i} className="h-20 rounded-lg border border-gray-100 animate-pulse bg-gray-50" />
                    ))}
                </div>
            )}

            {isError && (
                <p className="text-red-600 text-sm">Failed to load auctions.</p>
            )}

            {data && (
                <div className="space-y-3">
                    {data.data.map((auction) => (
                        <div
                            key={auction.id}
                            className="flex items-center justify-between rounded-lg border border-gray-200 px-5 py-4 hover:border-gray-400 transition-colors"
                        >
                            <div>
                                <h2 className="font-medium">{auction.title}</h2>
                                {auction.starts_at && (
                                    <p className="text-xs text-gray-500 mt-0.5">
                                        {new Date(auction.starts_at).toLocaleDateString('en-GB', {
                                            day: 'numeric',
                                            month: 'long',
                                            year: 'numeric',
                                        })}
                                        {auction.ends_at && (
                                            <> &ndash; {new Date(auction.ends_at).toLocaleDateString('en-GB', {
                                                day: 'numeric',
                                                month: 'long',
                                                year: 'numeric',
                                            })}</>
                                        )}
                                    </p>
                                )}
                            </div>
                            <div className="flex items-center gap-3">
                                <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${statusColor[auction.status] ?? 'bg-gray-100 text-gray-600'}`}>
                                    {auction.status}
                                </span>
                                {auction.status === 'live' && (
                                    <Link
                                        to={`/auctions/${auction.id}/live`}
                                        className="rounded-md bg-black text-white px-4 py-1.5 text-sm font-medium hover:bg-gray-800"
                                    >
                                        Join Live
                                    </Link>
                                )}
                            </div>
                        </div>
                    ))}

                    {data.data.length === 0 && (
                        <p className="text-gray-500 text-sm text-center py-12">No auctions scheduled.</p>
                    )}
                </div>
            )}
        </div>
    );
}
