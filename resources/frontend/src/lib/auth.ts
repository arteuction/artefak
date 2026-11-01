import axios from 'axios';
import { api } from './api';

export type AuthUser = {
    id: number;
    name: string;
    email: string;
    role: 'buyer' | 'artist' | 'admin' | null;
};

export async function getAuthUser(): Promise<AuthUser | null> {
    try {
        const res = await api.get<AuthUser>('/me');
        return res.data;
    } catch {
        return null;
    }
}

export async function loginWithSanctum(email: string, password: string): Promise<void> {
    // CSRF cookie lives outside /api/v1 — use absolute path
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
    await api.post('/login', { email, password });
}

export async function logout(): Promise<void> {
    await api.post('/logout');
}
