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
import ArtworkEditPage from './pages/artist/ArtworkEditPage';
import OfferListPage from './pages/buyer/OfferListPage';
import ConsignmentListPage from './pages/gallery/ConsignmentListPage';
import PublicationListPage from './pages/library/PublicationListPage';
import PublicationDetailPage from './pages/library/PublicationDetailPage';
import OfferManagePage from './pages/gallery/OfferManagePage';
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
                    <Route path="dashboard/artworks/:slug/edit" element={<ArtworkEditPage />} />
                    <Route path="offers" element={<OfferListPage />} />
                    <Route path="library" element={<PublicationListPage />} />
                    <Route path="library/:slug" element={<PublicationDetailPage />} />
                    <Route path="gallery/consignments" element={<ConsignmentListPage />} />
                    <Route path="gallery/offers" element={<OfferManagePage />} />
                    <Route path="*" element={<NotFoundPage />} />
                </Route>
            </Routes>
        </AuthProvider>
    );
}
