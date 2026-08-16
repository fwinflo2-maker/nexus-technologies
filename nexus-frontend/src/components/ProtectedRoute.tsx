import type { ReactNode } from 'react';
import { Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

/**
 * Garde de route — bloque l'accès aux pages privées tant que la session
 * n'a pas été restaurée via /api/me. Pendant la vérification, on affiche
 * un loader (silencieux) pour éviter une redirection clignotante vers /login.
 */
export function ProtectedRoute({ children }: { children: ReactNode }) {
  const { isAuthenticated, isLoaded } = useAuth();

  // Pendant la restauration de session : on attend avant de décider
  if (!isLoaded) {
    return (
      <div style={{
        position: 'fixed',
        inset: 0,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        background: '#0B0B0F',
      }}>
        <div className="nexus-spinner" aria-label="Chargement" />
      </div>
    );
  }

  // Session non valide → redirection vers /login
  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  return <>{children}</>;
}
