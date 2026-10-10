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

export type GalleryConsignment = {
    id: number;
    artwork_id: number;
    gallery_id: number;
    status: 'pending' | 'active' | 'completed' | 'rejected';
    commission_bps: number;
    notes: string | null;
    created_at: string;
    artwork?: { id: number; title: string; slug: string; status: string; medium: string | null };
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

export type Venue = {
    id: number;
    name: string;
    slug: string;
    address: string | null;
    city: string | null;
    description: string | null;
    lat: number | null;
    lng: number | null;
    website_url: string | null;
    status: 'active' | 'inactive';
};

export type VenueArtwork = {
    id: number;
    artwork: {
        id: number;
        title: string;
        slug: string;
        medium: string | null;
        artist: { id: number; name: string };
    };
    location_label: string | null;
    iiif_manifest_url: string | null;
    qr_token: string | null;
};

export type BookAuthor = {
    id: number;
    name: string;
    role: string | null;
    royalty_bps: number | null;
};

export type Publication = {
    id: number;
    title: string;
    slug: string;
    description: string | null;
    short_description: string | null;
    isbn: string | null;
    publisher: string | null;
    language: string | null;
    page_count: number | null;
    publication_year: number | null;
    price_cents: number;
    currency: string;
    is_free: boolean;
    status: 'draft' | 'pending_review' | 'published' | 'rejected' | 'unpublished';
    is_featured: boolean;
    created_at: string;
    book_authors?: Array<{ author: BookAuthor; role: string | null }>;
    files?: Array<{ id: number; type: string; size_bytes: number | null; version: string | null }>;
    // entitlement computed by backend when authenticated
    access?: 'none' | 'purchased' | 'free';
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
