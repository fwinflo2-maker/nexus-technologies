import { useState, useEffect, useCallback, useMemo } from 'react';
import {
  apiNotificationsList,
  type ApiNotification,
  type NotificationType,
} from '../../api/client';
import { useNotifications } from '../../context/NotificationsContext';
import { NOTIFICATION_TYPES, notificationMeta } from '../../data/notifications';
import { useDashT, localeFor } from '../../data/dashboard-i18n';
import { useI18n } from '../../context/I18nContext';

/**
 * Centre de notifications (/notifications).
 *
 * - Liste groupée par date (Aujourd'hui / Hier / date complète).
 * - Filtre par type : transfert, quote, kyc, securite, business, systeme.
 * - Actions : « Marquer comme lue » (par notification) et
 *   « Tout marquer comme lu ».
 * - Le compteur de la cloche se met à jour instantanément via
 *   NotificationsContext, sans rechargement de la page.
 * - Tous les textes visibles passent par l'i18n (dashTranslate).
 */

type Filter = 'all' | NotificationType;

const PAGE_SIZE = 15;

export default function NotificationsPage() {
  const { unreadCount, markRead, markAllRead } = useNotifications();
  const t = useDashT();
  const { lang } = useI18n();
  const locale = localeFor(lang);

  const [filter, setFilter] = useState<Filter>('all');
  const [items, setItems] = useState<ApiNotification[]>([]);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [markingAll, setMarkingAll] = useState(false);

  /** Libellé de groupe de date, localisé. */
  const groupLabel = useCallback(
    (dateKey: string): string => {
      const today = new Date();
      const todayKey = today.toISOString().slice(0, 10);

      const yesterday = new Date(today);
      yesterday.setDate(today.getDate() - 1);
      const yesterdayKey = yesterday.toISOString().slice(0, 10);

      if (dateKey === todayKey) return t('notifications.groupToday');
      if (dateKey === yesterdayKey) return t('notifications.groupYesterday');

      return new Date(dateKey + 'T00:00:00').toLocaleDateString(locale, {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
      });
    },
    [t, locale]
  );

  /** Heure locale courte (ex. « 14:05 »). */
  const formatTime = useCallback(
    (isoString: string): string =>
      new Date(isoString).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' }),
    [locale]
  );

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
      setError(resp.error || t('notifications.loadError'));
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
  }, [t]);

  useEffect(() => {
    fetchPage(filter, 1, false);
  }, [filter, fetchPage]);

  /** Notifications groupées par jour (clé YYYY-MM-DD). */
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

  /** Marque une notification comme lue (locale + compteur global). */
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

  // --- États : chargement / erreur -------------------------------------------
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
          <h2 style={{ color: 'var(--text-bright)', marginBottom: 10 }}>{t('notifications.loadError')}</h2>
          <p style={{ color: 'var(--text-mid)', marginBottom: 20 }}>{error}</p>
          <button className="btn btn-cyan" onClick={() => fetchPage(filter, 1, false)}>↻ {t('common.retry')}</button>
        </div>
      </div>
    );
  }

  return (
    <div className="page">
      {/* En-tête */}
      <div className="page-header animate-up">
        <div className="page-label">Nexus — {t('notifications.pageLabel')}</div>
        <div className="page-title">
          {t('page.notifications')} <span className="gc">{t('notifications.title')}</span>
        </div>
        <p style={{ marginTop: 8, fontSize: 13, color: 'var(--text-mid)', maxWidth: 520 }}>
          {t('notifications.subtitle')}
        </p>
      </div>

      {/* Statistique + action globale */}
      <div className="g4 animate-up delay-1" style={{ marginBottom: 20 }}>
        <div className="card stat-card">
          <div className="stat-label">{t('notifications.unread')}</div>
          <div className="stat-value" style={{ color: 'var(--red)' }}>{unreadCount}</div>
          <div style={{ fontSize: 10, color: 'var(--text-dim)' }}>{t('notifications.unreadSub')}</div>
        </div>
        <div className="card stat-card">
          <div className="stat-label">{t('notifications.total')}</div>
          <div className="stat-value" style={{ color: 'var(--cyan)' }}>{total}</div>
          <div style={{ fontSize: 10, color: 'var(--text-dim)' }}>{filter === 'all' ? t('notifications.allTypes') : t('notifications.filterActive')}</div>
        </div>
        <div className="card card-hi-gr" style={{ display: 'flex', flexDirection: 'column', justifyContent: 'center', gap: 10 }}>
          <div style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-bright)' }}>{t('notifications.markAll')}</div>
          <div style={{ fontSize: 10, color: 'var(--text-dim)' }}>{t('notifications.markAll.sub')}</div>
          <button className="btn btn-ghost" style={{ fontSize: 10, alignSelf: 'flex-start' }} disabled={!hasUnread || markingAll} onClick={handleMarkAllRead}>
            {markingAll ? '…' : t('notifications.markAll.btn')}
          </button>
        </div>
      </div>

      {/* Filtre par type */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16, flexWrap: 'wrap' }}>
        <div style={{ fontSize: 10, fontWeight: 600, color: 'var(--text-dim)', letterSpacing: '2px', textTransform: 'uppercase' }}>{t('notifications.filter')}</div>
        <div className="mode-toggle" style={{ flexWrap: 'wrap' }}>
          <button
            className={`mode-btn ${filter === 'all' ? 'active' : ''}`}
            onClick={() => setFilter('all')}
          >
            {t('notifications.all')}
          </button>
          {NOTIFICATION_TYPES.map((type) => {
            const meta = notificationMeta(type);
            return (
              <button
                key={type}
                className={`mode-btn ${filter === type ? 'active' : ''}`}
                onClick={() => setFilter(type)}
                style={filter === type ? { color: meta.color } : undefined}
              >
                {meta.icon} {t('notifType.' + type)}
              </button>
            );
          })}
        </div>
      </div>

      {/* Liste groupée par date */}
      {items.length === 0 ? (
        <div className="card animate-up delay-2" style={{ padding: 48, textAlign: 'center' }}>
          <div style={{ fontSize: 32, marginBottom: 12 }}>🔕</div>
          <div style={{ fontSize: 15, fontWeight: 700, color: 'var(--text-bright)' }}>{t('notifications.empty')}</div>
          <p style={{ fontSize: 11, color: 'var(--text-mid)', marginTop: 6, marginBottom: 16 }}>
            {filter === 'all'
              ? t('notifications.empty.all')
              : t('notifications.empty.filter')}
          </p>
          {filter !== 'all' && (
            <button className="btn btn-cyan" style={{ fontSize: 11 }} onClick={() => setFilter('all')}>
              {t('notifications.viewAll')}
            </button>
          )}
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
                        <span className={`pill ${meta.pill}`} style={{ fontSize: 7, flexShrink: 0 }}>{t('notifType.' + n.type)}</span>
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
                        {t('notifications.markRead')}
                      </button>
                    ) : (
                      <span className="pill p-gr" style={{ fontSize: 8, flexShrink: 0 }}>{t('notifications.read')}</span>
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
            {loadingMore ? t('notifications.loadingMore') : t('notifications.loadMore', { remaining: String(total - items.length) })}
          </button>
        </div>
      )}
    </div>
  );
}
