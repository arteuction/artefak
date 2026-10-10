import axios from 'axios';

export const api = axios.create({
    baseURL: '/api/v1',
    headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
    withCredentials: true,
});

api.interceptors.request.use((config) => {
    const token = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];
    if (token) {
        config.headers['X-XSRF-TOKEN'] = decodeURIComponent(token);
    }
    return config;
});

export type PaginatedResponse<T> = {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    links: {
        next: string | null;
        prev: string | null;
    };
};

export type Artwork = {
    id: number;
    title: string;
    slug: string;
    status: string;
    medium: string | null;
    dimensions: string | null;
    year_created: number | null;
    description: string | null;
    is_original: boolean;
    edition_number: number | null;
    edition_total: number | null;
    created_at: string;
    updated_at: string;
    artist: { id: number; name: string };
};

export type Auction = {
    id: number;
    title: string;
    slug: string;
    status: string;
    starts_at: string | null;
    ends_at: string | null;
    created_at: string;
};

export type SellNowOffer = {
    id: number;
    art_lot_id: number;
    offered_price_cents: number;
    counter_price_cents: number | null;
    currency: string;
    status: 'pending' | 'countered' | 'accepted' | 'rejected' | 'expired' | 'paid';
    notes: string | null;
    expires_at: string | null;
    created_at: string;
    updated_at: string;
    artwork?: { id: number; title: string; slug: string };
};

export type ArtLot = {
    id: number;
    artwork_id: number;
    auction_id: number | null;
    status: string;
    reserve_price_cents: number | null;
    starting_bid_cents: number | null;
    current_bid_cents: number | null;
    bid_count: number;
    currency: string;
};
