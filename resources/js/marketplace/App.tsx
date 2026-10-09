import { Routes, Route } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import Layout from './components/Layout';
import ArtworkListPage from './pages/ArtworkListPage';
import ArtworkDetailPage from './pages/ArtworkDetailPage';
import AuctionListPage from './pages/AuctionListPage';
import AuctionRoomPage from './pages/AuctionRoomPage';
import ArtistProfilePage from './pages/ArtistProfilePage';
import LoginPage from './pages/LoginPage';
import DashboardPage from './pages/artist/DashboardPage';
import ArtworkCreatePage from './pages/artist/ArtworkCreatePage';
import NotFoundPage from './pages/NotFoundPage';

export default function App() {
    return (
        <AuthProvider>
            <Routes>
                <Route element={<Layout />}>
                    <Route index element={<ArtworkListPage />} />
                    <Route path="artworks" element={<ArtworkListPage />} />
                    <Route path="artworks/:slug" element={<ArtworkDetailPage />} />
                    <Route path="auctions" element={<AuctionListPage />} />
                    <Route path="auctions/:id/live" element={<AuctionRoomPage />} />
                    <Route path="artists/:id" element={<ArtistProfilePage />} />
                    <Route path="login" element={<LoginPage />} />
                    <Route path="dashboard" element={<DashboardPage />} />
                    <Route path="dashboard/artworks/new" element={<ArtworkCreatePage />} />
                    <Route path="*" element={<NotFoundPage />} />
                </Route>
            </Routes>
        </AuthProvider>
    );
}
