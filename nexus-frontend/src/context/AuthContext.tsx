import { createContext, useContext, useState, useEffect, useCallback, type ReactNode } from 'react';
import { apiGoogleAuth, apiMe, apiLogout, type ApiUser } from '../api/client';

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
  loginWithGoogle: (credential: string) => Promise<{ success: boolean; error?: string }>;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

// ─── Helpers ─────────────────────────────────────────────────────────────

function toUser(apiUser: ApiUser): User {
  return {
    ...apiUser,
    name: apiUser.full_name,
  };
}

// ─── Provider ────────────────────────────────────────────────────────────

export function AuthProvider({ children }: { children: ReactNode }) {
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

  /** Connexion Google OAuth — le backend crée ou trouve l'utilisateur et renvoie un token */
  const loginWithGoogle = async (credential: string) => {
    const resp = await apiGoogleAuth(credential);
    if (resp.success && resp.data) {
      setUser(toUser(resp.data.user));
      return { success: true };
    }
    return { success: false, error: resp.error };
  };

  /** Déconnexion — révoque le token côté serveur et nettoie l'état local */
  const logout = async () => {
    await apiLogout();
    setUser(null);
  };

  return (
    <AuthContext.Provider
      value={{
        user,
        isLoaded,
        isAuthenticated: user !== null,
        refreshSession,
        loginWithGoogle,
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