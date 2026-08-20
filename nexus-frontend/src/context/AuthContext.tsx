import { createContext, useContext, useState, useEffect, useCallback, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { apiMe, apiLogout, type ApiUser } from '../api/client';

// ─── Types ───────────────────────────────────────────────────────────────

/** User étendu avec `name` pour rétro-compatibilité avec les composants existants */
interface User extends ApiUser {
  name: string;
}

interface AuthContextType {
  user: User | null;
  /** Vrai une fois que la session a été restaurée (ou refusée) au démarrage */
  isLoaded: boolean;
  isAuthenticated: boolean;
  /** Appelé après un login/register réussi — revalide la session via /api/me */
  refreshSession: () => Promise<void>;
  /**
   * Déconnexion. Redirige vers le login de l'espace courant
   * (client → /login, superadmin → /admin-login, staff → /staff-login),
   * ou vers `redirectTo` si fourni.
   */
  logout: (redirectTo?: string) => Promise<void>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

// ─── Helpers ─────────────────────────────────────────────────────────────

function toUser(apiUser: ApiUser): User {
  return {
    ...apiUser,
    name: apiUser.full_name,
  };
}

/** Login cible selon l'URL où la déconnexion a lieu. */
export function loginPathForLocation(pathname: string): string {
  if (pathname.startsWith('/admin') || pathname.startsWith('/admin-login')) {
    return '/admin-login';
  }
  if (pathname.startsWith('/staff') || pathname.startsWith('/staff-login')) {
    return '/staff-login';
  }
  return '/login';
}

/** Destination post-login selon le rôle plateforme. */
export function homePathForRole(platformRole: string | undefined | null): string {
  if (platformRole === 'superadmin') return '/admin';
  if (platformRole && platformRole !== 'user') return '/staff';
  return '/dashboard';
}

// ─── Provider ────────────────────────────────────────────────────────────

export function AuthProvider({ children }: { children: ReactNode }) {
  const navigate = useNavigate();
  const [user, setUser] = useState<User | null>(null);
  const [isLoaded, setIsLoaded] = useState(false);

  /**
   * Restaure la session côté serveur au démarrage.
   * Si le token est présent et valide, l'utilisateur est restauré.
   * Sinon, le state reste null et on passe en mode déconnecté.
   */
  const refreshSession = useCallback(async () => {
    const resp = await apiMe();
    if (resp.success && resp.data?.user) {
      setUser(toUser(resp.data.user));
    } else {
      setUser(null);
    }
    setIsLoaded(true);
  }, []);

  // Au montage, on tente de restaurer la session via /api/me
  useEffect(() => {
    refreshSession();
  }, [refreshSession]);

  /**
   * Déconnexion — révoque le token, navigue vers le bon login, puis vide l'état.
   * Naviguer AVANT setUser(null) évite que PublicRouter tombe sur le catch-all
   * `/login` alors qu'on était sur /admin ou /staff.
   */
  const logout = useCallback(async (redirectTo?: string) => {
    try {
      await apiLogout();
    } catch {
      // Même si la révocation serveur échoue (réseau), on déconnecte localement.
    }
    const target = redirectTo ?? loginPathForLocation(window.location.pathname);
    // Vider la session d'abord : PublicRouter + PublicAuthFallback couvrent déjà
    // le bon login. navigate() verrouille l'URL même si le catch-all lag.
    setUser(null);
    navigate(target, { replace: true });
  }, [navigate]);

  return (
    <AuthContext.Provider
      value={{
        user,
        isLoaded,
        isAuthenticated: user !== null,
        refreshSession,
        logout,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within AuthProvider');
  }
  return context;
}
