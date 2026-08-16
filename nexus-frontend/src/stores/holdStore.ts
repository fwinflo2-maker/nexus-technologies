import { create } from 'zustand';
import {
  apiPendingHolds,
  apiCaptureHold,
  apiReleaseHold,
  type PendingHold,
} from '../api/client';

/**
 * Store Zustand des holds pending.
 *
 * Centralise la liste des réservations de fonds de l'utilisateur afin que
 * WalletPage et PendingHolds restent synchronisés SANS rechargement complet :
 *  - `fetchHolds`      → relit la liste depuis GET /api/wallets/holds ;
 *  - `capture`         → capture un hold puis rafraîchit ;
 *  - `release`         → libère un hold puis rafraîchit ;
 *  - `clear`           → vide l'état (déconnexion).
 *
 * Les montants sont des strings décimales (jamais de float) et le TTL
 * (`remaining_seconds`) est calculé côté backend.
 */

interface HoldStoreState {
  holds: PendingHold[];
  loading: boolean;
  error: string | null;
  fetchHolds: () => Promise<void>;
  capture: (operationId: string) => Promise<boolean>;
  release: (operationId: string) => Promise<boolean>;
  clear: () => void;
}

export const useHoldStore = create<HoldStoreState>((set, get) => ({
  holds: [],
  loading: false,
  error: null,

  fetchHolds: async () => {
    set({ loading: true, error: null });
    const resp = await apiPendingHolds('pending');
    if (resp.success && resp.data) {
      set({ holds: resp.data.holds, loading: false });
      return;
    }
    set({ error: resp.error ?? 'Erreur lors du chargement des holds.', loading: false });
  },

  capture: async (operationId: string) => {
    const key = `hold-capture-${operationId}`;
    const resp = await apiCaptureHold(operationId, key);
    if (resp.success) {
      await get().fetchHolds();
      return true;
    }
    set({ error: resp.error ?? 'Erreur lors de la capture du hold.' });
    return false;
  },

  release: async (operationId: string) => {
    const key = `hold-release-${operationId}`;
    const resp = await apiReleaseHold(operationId, key);
    if (resp.success) {
      await get().fetchHolds();
      return true;
    }
    set({ error: resp.error ?? 'Erreur lors de la libération du hold.' });
    return false;
  },

  clear: () => set({ holds: [], loading: false, error: null }),
}));
