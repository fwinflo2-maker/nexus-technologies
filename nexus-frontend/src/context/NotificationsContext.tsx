import { createContext, useContext, useState, useEffect, useCallback, type ReactNode } from 'react';
import {
  apiNotificationsUnreadCount,
  apiNotificationsRead,
  apiNotificationsReadAll,
} from '../api/client';

/**
 * Contexte global des notifications.
 *
 * Centralise le compteur de notifications non lues afin que la cloche de la
 * Topbar et le centre de notifications restent synchronisés SANS rechargement
 * complet de la page :
 *  - `refreshUnread`  → relit le compteur depuis l'API ;
 *  - `markRead`       → marque une notification comme lue puis rafraîchit ;
 *  - `markAllRead`    → marque tout comme lu puis rafraîchit.
 *
 * Un polling (30 s) maintient le badge de la cloche à jour, même lorsqu'on
 * navigue entre les pages du dashboard.
 */

interface NotificationsContextValue {
  unreadCount: number;
  refreshUnread: () => Promise<void>;
  markRead: (id: number) => Promise<boolean>;
  markAllRead: () => Promise<boolean>;
}

const NotificationsContext = createContext<NotificationsContextValue | undefined>(undefined);

/** Intervalle de rafraîchissement automatique du compteur (ms). */
const POLL_INTERVAL_MS = 30_000;

export function NotificationsProvider({ children }: { children: ReactNode }) {
  const [unreadCount, setUnreadCount] = useState(0);

  const refreshUnread = useCallback(async () => {
    const resp = await apiNotificationsUnreadCount();
    if (resp.success && resp.data) {
      setUnreadCount(resp.data.unread_count);
    }
  }, []);

  // Au montage + polling : le badge reste à jour sans action utilisateur.
  useEffect(() => {
    refreshUnread();
    const interval = window.setInterval(refreshUnread, POLL_INTERVAL_MS);
    return () => window.clearInterval(interval);
  }, [refreshUnread]);

  const markRead = useCallback(async (id: number) => {
    const resp = await apiNotificationsRead(id);
    if (resp.success && resp.data) {
      setUnreadCount(resp.data.unread_count);
      return true;
    }
    return false;
  }, []);

  const markAllRead = useCallback(async () => {
    const resp = await apiNotificationsReadAll();
    if (resp.success && resp.data) {
      setUnreadCount(resp.data.unread_count);
      return true;
    }
    return false;
  }, []);

  return (
    <NotificationsContext.Provider value={{ unreadCount, refreshUnread, markRead, markAllRead }}>
      {children}
    </NotificationsContext.Provider>
  );
}

export function useNotifications(): NotificationsContextValue {
  const context = useContext(NotificationsContext);
  if (!context) {
    throw new Error('useNotifications doit être utilisé dans un NotificationsProvider.');
  }
  return context;
}
