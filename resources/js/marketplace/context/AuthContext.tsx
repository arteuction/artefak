import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import type { AuthUser } from '@/lib/auth';
import { getAuthUser } from '@/lib/auth';

type AuthContextValue = {
    user: AuthUser | null;
    loading: boolean;
    refresh: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue>({
    user: null,
    loading: true,
    refresh: async () => {},
});

export function AuthProvider({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<AuthUser | null>(null);
    const [loading, setLoading] = useState(true);

    const refresh = async () => {
        const u = await getAuthUser();
        setUser(u);
    };

    useEffect(() => {
        void getAuthUser()
            .then(setUser)
            .finally(() => setLoading(false));
    }, []);

    return (
        <AuthContext.Provider value={{ user, loading, refresh }}>
            {children}
        </AuthContext.Provider>
    );
}

export function useAuth() {
    return useContext(AuthContext);
}
