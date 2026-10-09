import { Routes, Route } from 'react-router-dom';
import Layout from './components/Layout';
import ArtworkListPage from './pages/ArtworkListPage';
import ArtworkDetailPage from './pages/ArtworkDetailPage';
import AuctionListPage from './pages/AuctionListPage';
import AuctionRoomPage from './pages/AuctionRoomPage';
import ArtistProfilePage from './pages/ArtistProfilePage';
import NotFoundPage from './pages/NotFoundPage';

export default function App() {
    return (
        <Routes>
            <Route element={<Layout />}>
                <Route index element={<ArtworkListPage />} />
                <Route path="artworks" element={<ArtworkListPage />} />
                <Route path="artworks/:slug" element={<ArtworkDetailPage />} />
                <Route path="auctions" element={<AuctionListPage />} />
                <Route path="auctions/:id/live" element={<AuctionRoomPage />} />
                <Route path="artists/:id" element={<ArtistProfilePage />} />
                <Route path="*" element={<NotFoundPage />} />
            </Route>
        </Routes>
    );
}
