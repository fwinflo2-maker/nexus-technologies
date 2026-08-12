/**
 * Bouton de connexion / inscription Google via Identity Services (GIS).
 *
 * Ce composant :
 * 1. Charge le script `https://accounts.google.com/gsi/client` (une seule fois).
 * 2. Initialise le SDK avec le Client ID fourni.
 * 3. Rend le bouton officiel Google dans un conteneur stylé.
 * 4. Appelle `onCredential(credential)` au succès, ou `onError(message)` en cas d'échec.
 *
 * Le bouton utilise le design system officiel de Google (marque obligatoire).
 * Le conteneur applique le style glass du thème NEXUS.
 */

import { useEffect, useRef, useCallback } from 'react';
import { useI18n } from '../context/I18nContext';

// Client ID public fourni par Google Cloud Console (identifiant client OAuth).
const GOOGLE_CLIENT_ID = '483873643229-7ijb4asiq7mc6v5laps74ueqblstmpu7.apps.googleusercontent.com';
const GIS_SCRIPT_SRC = 'https://accounts.google.com/gsi/client';

interface GoogleButtonProps {
  onCredential: (credential: string) => void;
  onError?: (message: string) => void;
}

declare global {
  interface Window {
    google?: {
      accounts: {
        id: {
          initialize: (config: {
            client_id: string;
            callback: (response: { credential?: string }) => void;
          }) => void;
          renderButton: (
            parent: HTMLElement,
            options: {
              theme?: string;
              size?: string;
              width?: number;
              shape?: string;
              text?: string;
            },
          ) => void;
        };
      };
    };
  }
}

export function GoogleButton({ onCredential, onError }: GoogleButtonProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const initRef = useRef(false);
  const { t } = useI18n();

  const handleCredential = useCallback(
    (response: { credential?: string }) => {
      if (response.credential) {
        onCredential(response.credential);
      } else {
        onError?.('google_no_credential');
      }
    },
    [onCredential, onError],
  );

  useEffect(() => {
    // Chargement du script GIS (une seule fois).
    const loadScript = (): Promise<void> =>
      new Promise((resolve) => {
        if (document.querySelector(`script[src="${GIS_SCRIPT_SRC}"]`)) {
          resolve();
          return;
        }
        const s = document.createElement('script');
        s.src = GIS_SCRIPT_SRC;
        s.async = true;
        s.onload = () => resolve();
        document.body.appendChild(s);
      });

    loadScript().then(() => {
      if (!window.google?.accounts?.id) return;

      if (!initRef.current) {
        window.google.accounts.id.initialize({
          client_id: GOOGLE_CLIENT_ID,
          callback: handleCredential,
        });
        initRef.current = true;
      }

      // Rendu du bouton officiel Google dans le conteneur.
      if (containerRef.current && !containerRef.current.hasChildNodes()) {
        window.google.accounts.id.renderButton(containerRef.current, {
          theme: 'outline',
          size: 'large',
          width: containerRef.current.clientWidth || 320,
          shape: 'pill',
          text: 'continue_with',
        });
      }
    });
  }, [handleCredential]);

  return (
    <div className="google-btn-wrapper">
      <div ref={containerRef} className="google-btn-container" />
      {/* Contenu accessible pour les lecteurs d'écran */}
      <noscript>
        <button type="button" className="btn btn-ghost btn-lg" disabled>
          {t('auth_google_btn')}
        </button>
      </noscript>
    </div>
  );
}
