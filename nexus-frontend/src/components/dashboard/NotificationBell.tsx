import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { apiNotificationsList, type ApiNotification } from '../../api/client';
import { useNotifications } from '../../context/NotificationsContext';
import { notificationMeta } from '../../data/notifications';

/**
 * Cloche de notifications de la Topbar.
 *
 * - Badge rouge = nombre de non-lues (fourni par NotificationsContext, qui
 *   poll toutes les 30 s : le compteur se met à jour sans rechargement).
 * - Panneau déroulant : les 5 dernières notifications + « Tout voir ».
 * - Cliquer sur une notification la marque comme lue puis ouvre le centre.
 */

/** Format relatif court, identique à l'esprit du dashboard (« Il y a 5 min »). */
function formatRelativeTime(isoString: string): string {
  const date = new Date(isoString);
  const diffMs = Date.now() - date.getTime();
  const diffMinutes = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMinutes / 60);
  const diffDays = Math.floor(diffHours / 24);

  if (diffMinutes < 1) return "À l'instant";
  if (diffMinutes < 60) return `Il y a ${diffMinutes} min`;
  if (diffHours < 24) return `Il y a ${diffHours}h`;
  if (diffDays === 1) return 'Hier';
  return date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
}

export default function NotificationBell() {
  const { unreadCount, refreshUnread, markRead, markAllRead } = useNotifications();
  const [open, setOpen] = useState(false);
  const [recent, setRecent] = useState<ApiNotification[]>([]);
  const [loading, setLoading] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);
  const navigate = useNavigate();

  // Ferme le panneau lors d'un clic extérieur.
  useEffect(() => {
    if (!open) return;
    const onOutsideClick = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener('mousedown', onOutsideClick);
    return () => document.removeEventListener('mousedown', onOutsideClick);
  }, [open]);

  const toggle = async () => {
    const next = !open;
    setOpen(next);
    if (next) {
      // Chargement des 5 dernières + rafraîchissement du compteur.
      setLoading(true);
      const resp = await apiNotificationsList({ page: 1, per_page: 5 });
      if (resp.success && resp.data) {
        setRecent(resp.data.items);
      } else {
        setRecent([]);
      }
      setLoading(false);
      await refreshUnread();
    }
  };

  const handleItemClick = async (n: ApiNotification) => {
    if (!n.is_read) {
      await markRead(n.id);
    }
    setOpen(false);
    navigate('/notifications');
  };

  const handleMarkAll = async () => {
    await markAllRead();
    setRecent((prev) => prev.map((n) => ({ ...n, is_read: true })));
  };

  return (
    <div ref={containerRef} style={{ position: 'relative' }}>
      <button
        onClick={toggle}
        title={open ? 'Fermer les notifications' : 'Notifications'}
        aria-label="Notifications"
        style={{
          position: 'relative',
          padding: '6px 10px',
          borderRadius: 8,
          border: open ? '1px solid var(--cyan)' : '1px solid var(--border)',
          background: open ? 'rgba(0,200,255,0.05)' : 'var(--panel2)',
          color: 'var(--text-bright)',
          fontSize: 14,
          cursor: 'pointer',
          transition: 'all 0.2s',
        }}
      >
        <span style={{ fontSize: 14, lineHeight: 1 }}>🔔</span>

        {/* Badge rouge du nombre de non-lues */}
        {unreadCount > 0 && (
          <span
            style={{
              position: 'absolute',
              top: -5,
              right: -5,
              minWidth: 16,
              height: 16,
              padding: '0 4px',
              borderRadius: 8,
              background: 'var(--red)',
              color: '#fff',
              fontSize: 9,
              fontWeight: 800,
              fontFamily: 'var(--font-mono)',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              boxShadow: '0 0 8px rgba(255,69,96,0.5)',
            }}
          >
            {unreadCount > 99 ? '99+' : unreadCount}
          </span>
        )}
      </button>

      {open && (
        <div
          style={{
            position: 'absolute',
            top: 'calc(100% + 10px)',
            right: 0,
            width: 360,
            maxWidth: 'calc(100vw - 32px)',
            background: 'var(--panel)',
            border: '1px solid var(--border)',
            borderRadius: 'var(--r-lg)',
            boxShadow: 'var(--shadow-md)',
            overflow: 'hidden',
            zIndex: 200,
          }}
        >
          {/* En-tête du panneau */}
          <div
            style={{
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              gap: 8,
              padding: '14px 16px',
              borderBottom: '1px solid var(--border-soft)',
              background: 'var(--panel2)',
            }}
          >
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)' }}>Notifications</span>
              {unreadCount > 0 && (
                <span
                  style={{
                    background: 'rgba(255,69,96,0.1)',
                    border: '1px solid rgba(255,69,96,0.3)',
                    color: 'var(--red)',
                    fontFamily: 'var(--font-mono)',
                    fontSize: 9,
                    fontWeight: 700,
                    padding: '1px 7px',
                    borderRadius: 100,
                  }}
                >
                  {unreadCount} non lue{unreadCount > 1 ? 's' : ''}
                </span>
              )}
            </div>
            {unreadCount > 0 && (
              <button
                onClick={handleMarkAll}
                style={{
                  background: 'transparent',
                  border: 'none',
                  color: 'var(--cyan)',
                  fontSize: 10,
                  fontWeight: 600,
                  cursor: 'pointer',
                  padding: 0,
                }}
              >
                Tout marquer comme lu
              </button>
            )}
          </div>

          {/* Liste des 5 dernières */}
          <div style={{ maxHeight: 320, overflowY: 'auto' }}>
            {loading ? (
              <div style={{ padding: 16, display: 'flex', flexDirection: 'column', gap: 10 }}>
                <div className="shimmer-bg" style={{ height: 54, borderRadius: 8 }} />
                <div className="shimmer-bg" style={{ height: 54, borderRadius: 8 }} />
                <div className="shimmer-bg" style={{ height: 54, borderRadius: 8 }} />
              </div>
            ) : recent.length === 0 ? (
              <div style={{ padding: 28, textAlign: 'center' }}>
                <div style={{ fontSize: 24, marginBottom: 8 }}>🔕</div>
                <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-bright)' }}>Aucune notification</div>
                <div style={{ fontSize: 10, color: 'var(--text-dim)', marginTop: 4 }}>
                  Les événements importants apparaîtront ici.
                </div>
              </div>
            ) : (
              recent.map((n) => {
                const meta = notificationMeta(n.type);
                return (
                  <button
                    key={n.id}
                    onClick={() => handleItemClick(n)}
                    style={{
                      display: 'flex',
                      gap: 10,
                      width: '100%',
                      textAlign: 'left',
                      padding: '11px 16px',
                      border: 'none',
                      borderBottom: '1px solid var(--border-soft)',
                      background: n.is_read ? 'transparent' : 'rgba(0,200,255,0.03)',
                      cursor: 'pointer',
                      transition: 'background 0.15s',
                    }}
                    onMouseEnter={(e) => { e.currentTarget.style.background = 'rgba(0,200,255,0.05)'; }}
                    onMouseLeave={(e) => { e.currentTarget.style.background = n.is_read ? 'transparent' : 'rgba(0,200,255,0.03)'; }}
                  >
                    <div className={`ib ${meta.ib}`} style={{ width: 32, height: 32, borderRadius: 8, fontSize: 14 }}>
                      {meta.icon}
                    </div>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                        <span
                          style={{
                            flex: 1,
                            fontSize: 11,
                            fontWeight: n.is_read ? 600 : 700,
                            color: 'var(--text-bright)',
                            whiteSpace: 'nowrap',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                          }}
                        >
                          {n.title}
                        </span>
                        {!n.is_read && <span className="dot d-c" style={{ flexShrink: 0 }} />}
                      </div>
                      <div
                        style={{
                          fontSize: 10,
                          color: 'var(--text-dim)',
                          marginTop: 2,
                          whiteSpace: 'nowrap',
                          overflow: 'hidden',
                          textOverflow: 'ellipsis',
                        }}
                      >
                        {n.message || meta.label}
                      </div>
                      <div style={{ fontSize: 9, color: 'var(--text-dim)', marginTop: 2, fontFamily: 'var(--font-mono)' }}>
                        {formatRelativeTime(n.created_at)}
                      </div>
                    </div>
                  </button>
                );
              })
            )}
          </div>

          {/* Pied du panneau : tout voir */}
          <Link
            to="/notifications"
            onClick={() => setOpen(false)}
            style={{
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              gap: 6,
              padding: '11px 16px',
              borderTop: '1px solid var(--border-soft)',
              background: 'var(--panel2)',
              color: 'var(--cyan)',
              fontSize: 11,
              fontWeight: 700,
              textDecoration: 'none',
            }}
          >
            Tout voir <span aria-hidden>→</span>
          </Link>
        </div>
      )}
    </div>
  );
}
