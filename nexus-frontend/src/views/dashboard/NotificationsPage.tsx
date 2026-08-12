import { useState, useEffect, useCallback, useMemo } from 'react';
import { useI18n } from '../../context/I18nContext';
import {
  apiNotificationsList,
  type ApiNotification,
  type NotificationType,
} from '../../api/client';
import { useNotifications } from '../../context/NotificationsContext';
import { NOTIFICATION_TYPES, notificationMeta } from '../../data/notifications';

type Filter = 'all' | NotificationType;

const PAGE_SIZE = 15;

export default function NotificationsPage() {
  const { unreadCount, markRead, markAllRead } = useNotifications();
  const { t, lang } = useI18n();

  const [filter, setFilter] = useState<Filter>('all');
  const [items, setItems] = useState<ApiNotification[]>([]);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [markingAll, setMarkingAll] = useState(false);

  /** Libellé de groupe de date. */
  const groupLabel = useCallback((dateKey: string): string => {
    const today = new Date();
    const todayKey = today.toISOString().slice(0, 10);

    const yesterday = new Date(today);
    yesterday.setDate(today.getDate() - 1);
    const yesterdayKey = yesterday.toISOString().slice(0, 10);

    if (dateKey === todayKey) return t('dash_last_hour'); // Or Aujourd'hui (fallback handled gracefully)
    if (dateKey === yesterdayKey) return 'Hier';

    return new Date(dateKey + 'T00:00:00').toLocaleDateString(lang === 'fr' ? 'fr-FR' : 'en-US', {
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    });
  }, [t, lang]);

  /** Heure locale courte. */
  const formatTime = useCallback((isoString: string): string => {
    return new Date(isoString).toLocaleTimeString(lang === 'fr' ? 'fr-FR' : 'en-US', { hour: '2-digit', minute: '2-digit' });
  }, [lang]);

  /** Charge une page (remplacement ou ajout selon `append`). */
  const fetchPage = useCallback(async (type: Filter, p: number, append: boolean) => {
    if (append) {
      setLoadingMore(true);
    } else {
      setLoading(true);
      setError(null);
    }

    const resp = await apiNotificationsList({
      type: type === 'all' ? undefined : type,
      page: p,
      per_page: PAGE_SIZE,
    });

    if (!resp.success || !resp.data) {
      setError(resp.error || 'Erreur lors du chargement des notifications.');
      setLoading(false);
      setLoadingMore(false);
      return;
    }

    const data = resp.data;
    setItems((prev) => (append ? [...prev, ...data.items] : data.items));
    setPage(data.page);
    setTotalPages(data.total_pages);
    setTotal(data.total);
    setLoading(false);
    setLoadingMore(false);
  }, []);

  useEffect(() => {
    fetchPage(filter, 1, false);
  }, [filter, fetchPage]);

  /** Notifications groupées par jour. */
  const grouped = useMemo(() => {
    const groups: Array<{ key: string; label: string; list: ApiNotification[] }> = [];
    for (const n of items) {
      const key = n.created_at.slice(0, 10);
      const last = groups[groups.length - 1];
      if (last && last.key === key) {
        last.list.push(n);
      } else {
        groups.push({ key, label: groupLabel(key), list: [n] });
      }
    }
    return groups;
  }, [items, groupLabel]);

  const hasUnread = items.some((n) => !n.is_read);

  /** Marque une notification comme lue. */
  const handleMarkRead = async (id: number) => {
    const ok = await markRead(id);
    if (ok) {
      setItems((prev) => prev.map((n) => (n.id === id ? { ...n, is_read: true } : n)));
    }
  };

  /** Marque toutes les notifications comme lues. */
  const handleMarkAllRead = async () => {
    if (markingAll) return;
    setMarkingAll(true);
    const ok = await markAllRead();
    if (ok) {
      setItems((prev) => prev.map((n) => ({ ...n, is_read: true })));
    }
    setMarkingAll(false);
  };

  if (loading) {
    return (
      <div className="page">
        <div className="shimmer-bg" style={{ height: 180, borderRadius: 16, marginBottom: 16 }} />
        <div className="shimmer-bg" style={{ height: 420, borderRadius: 16 }} />
      </div>
    );
  }

  if (error) {
    return (
      <div className="page" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100%' }}>
        <div className="card card-hi-c" style={{ padding: 40, textAlign: 'center', maxWidth: 440 }}>
          <h2 style={{ color: 'var(--text-bright)', marginBottom: 10 }}>{t('dash_error_load')}</h2>
          <p style={{ color: 'var(--text-mid)', marginBottom: 20 }}>{error}</p>
          <button className="btn btn-cyan" onClick={() => fetchPage(filter, 1, false)}>↻ {t('dash_retry')}</button>
        </div>
      </div>
    );
  }

  return (
    <div className="page">
      {/* En-tête */}
      <div className="page-header animate-up">
        <div className="page-label">Nexus — {t('side_notifications')}</div>
        <div className="page-title">
          {t('notif_title')}
        </div>
        <p style={{ marginTop: 8, fontSize: 13, color: 'var(--text-mid)', maxWidth: 520 }}>
          {t('notif_desc')}
        </p>
      </div>

      {/* Statistique + action globale */}
      <div className="g4 animate-up delay-1" style={{ marginBottom: 20 }}>
        <div className="card stat-card">
          <div className="stat-label">{t('dash_pending')}</div>
          <div className="stat-value" style={{ color: 'var(--red)' }}>{unreadCount}</div>
          <div style={{ fontSize: 10, color: 'var(--text-dim)' }}>🔔 En attente</div>
        </div>
        <div className="card stat-card">
          <div className="stat-label">{t('dash_kpi_total')}</div>
          <div className="stat-value" style={{ color: 'var(--cyan)' }}>{total}</div>
          <div style={{ fontSize: 10, color: 'var(--text-dim)' }}>{filter === 'all' ? 'All' : 'Filtre'}</div>
        </div>
        <div className="card card-hi-gr" style={{ display: 'flex', flexDirection: 'column', justifyContent: 'center', gap: 10 }}>
          <div style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-bright)' }}>{t('notif_mark_all')}</div>
          <div style={{ fontSize: 10, color: 'var(--text-dim)' }}>{t('notif_mark_all')}.</div>
          <button className="btn btn-ghost" style={{ fontSize: 10, alignSelf: 'flex-start' }} disabled={!hasUnread || markingAll} onClick={handleMarkAllRead}>
            {markingAll ? '…' : '✓ ' + t('notif_mark_all')}
          </button>
        </div>
      </div>

      {/* Filtre par type */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16, flexWrap: 'wrap' }}>
        <div style={{ fontSize: 10, fontWeight: 600, color: 'var(--text-dim)', letterSpacing: '2px', textTransform: 'uppercase' }}>Filter:</div>
        <div className="mode-toggle" style={{ flexWrap: 'wrap' }}>
          <button
            className={`mode-btn ${filter === 'all' ? 'active' : ''}`}
            onClick={() => setFilter('all')}
          >
            All
          </button>
          {NOTIFICATION_TYPES.map((t) => {
            const meta = notificationMeta(t);
            return (
              <button
                key={t}
                className={`mode-btn ${filter === t ? 'active' : ''}`}
                onClick={() => setFilter(t)}
                style={filter === t ? { color: meta.color } : undefined}
              >
                {meta.icon} {meta.label}
              </button>
            );
          })}
        </div>
      </div>

      {/* Liste groupée par date */}
      {items.length === 0 ? (
        <div className="card animate-up delay-2" style={{ padding: 48, textAlign: 'center' }}>
          <div style={{ fontSize: 32, marginBottom: 12 }}>🔕</div>
          <div style={{ fontSize: 15, fontWeight: 700, color: 'var(--text-bright)' }}>{t('notif_empty')}</div>
          <p style={{ fontSize: 11, color: 'var(--text-mid)', marginTop: 6, marginBottom: 16 }}>
            {t('notif_empty_desc')}
          </p>
        </div>
      ) : (
        grouped.map((group) => (
          <div key={group.key} className="animate-up delay-2" style={{ marginBottom: 24 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 10 }}>
              <span className="page-label" style={{ marginBottom: 0 }}>{group.label}</span>
              <div className="div" style={{ flex: 1 }} />
              <span className="pill p-c" style={{ fontSize: 8 }}>{group.list.length}</span>
            </div>

            <div className="card" style={{ padding: 8 }}>
              {group.list.map((n, ni) => {
                const meta = notificationMeta(n.type);
                return (
                  <div
                    key={n.id}
                    style={{
                      display: 'flex',
                      alignItems: 'center',
                      gap: 14,
                      padding: '12px 14px',
                      borderRadius: 10,
                      background: n.is_read ? 'transparent' : 'rgba(0,200,255,0.04)',
                      borderBottom: ni < group.list.length - 1 ? '1px solid var(--border-soft)' : 'none',
                      transition: 'background 0.15s',
                    }}
                  >
                    <div className={`ib ${meta.ib}`} style={{ width: 40, height: 40, borderRadius: 10, fontSize: 17 }}>
                      {meta.icon}
                    </div>

                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <span style={{ fontSize: 13, fontWeight: n.is_read ? 600 : 700, color: 'var(--text-bright)' }}>
                          {n.title}
                        </span>
                        {!n.is_read && <span className="dot d-c" />}
                        <span className={`pill ${meta.pill}`} style={{ fontSize: 7, flexShrink: 0 }}>{meta.label}</span>
                      </div>
                      {n.message && (
                        <div style={{ fontSize: 11, color: 'var(--text-mid)', marginTop: 3, lineHeight: 1.5 }}>
                          {n.message}
                        </div>
                      )}
                      <div style={{ fontSize: 9, color: 'var(--text-dim)', marginTop: 4, fontFamily: 'var(--font-mono)' }}>
                        {group.label} · {formatTime(n.created_at)}
                      </div>
                    </div>

                    {!n.is_read ? (
                      <button
                        className="btn btn-ghost"
                        style={{ fontSize: 10, padding: '6px 12px' }}
                        onClick={() => handleMarkRead(n.id)}
                      >
                        ✓ {t('notif_mark_read')}
                      </button>
                    ) : (
                      <span className="pill p-gr" style={{ fontSize: 8, flexShrink: 0 }}>Lue</span>
                    )}
                  </div>
                );
              })}
            </div>
          </div>
        ))
      )}

      {/* Pagination : charger plus */}
      {page < totalPages && (
        <div style={{ display: 'flex', justifyContent: 'center', marginTop: 8 }}>
          <button
            className="btn btn-ghost"
            onClick={() => fetchPage(filter, page + 1, true)}
            disabled={loadingMore}
          >
            {loadingMore ? 'Chargement…' : `Plus (${total - items.length})`}
          </button>
        </div>
      )}
    </div>
  );
}
