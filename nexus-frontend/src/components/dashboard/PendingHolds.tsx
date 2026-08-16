import { useEffect, useCallback } from 'react';
import { useHoldStore } from '../../stores/holdStore';
import type { PendingHold } from '../../api/client';

/**
 * PendingHolds — Réservations de fonds en attente (holds pending).
 *
 * - Charge la liste via GET /api/wallets/holds?status=pending (store Zustand).
 * - Affiche chaque hold : montant (string décimale, jamais de float), devise,
 *   temps restant avant expiration (countdown), boutons Capture / Libérer.
 * - Après une capture ou une libération, la liste est rafraîchie automatiquement.
 *
 * Style : réutilise exclusivement les classes de dashboard-system.css
 * (cards, pills, mono, g2/g4) — aucun nouveau composant visuel.
 */

const CURRENCY_SYMBOL: Record<string, string> = {
  EUR: '€',
  USD: '$',
  GBP: '£',
  XAF: 'XAF',
  USDT: 'USDT',
  USDC: 'USDC',
};

/** Formate une string décimale (ex. "100.00000000") en affichage lisible. */
function formatHoldAmount(amount: string, currency: string): string {
  const num = Number(amount);
  const digits = Number.isInteger(num) ? 2 : 8;
  const formatted = num.toLocaleString('fr-FR', {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  });
  const symbol = CURRENCY_SYMBOL[currency] ?? currency;
  return `${symbol} ${formatted}`;
}

/** Formatage "mm:ss" ou "h:mm:ss" selon le temps restant. */
function formatRemaining(seconds: number): string {
  const s = Math.max(0, Math.floor(seconds));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = s % 60;
  const mm = String(m).padStart(2, '0');
  const ss = String(sec).padStart(2, '0');
  return h > 0 ? `${h}:${mm}:${ss}` : `${mm}:${ss}`;
}

export default function PendingHolds() {
  const { holds, loading, error, fetchHolds, capture, release } = useHoldStore();

  useEffect(() => {
    void fetchHolds();
  }, [fetchHolds]);

  /** Recharge la liste après une action (boutons désactivés pendant l'appel). */
  const handleCapture = useCallback((hold: PendingHold) => {
    void capture(hold.operation_id);
  }, [capture]);

  const handleRelease = useCallback((hold: PendingHold) => {
    void release(hold.operation_id);
  }, [release]);

  return (
    <div className="card" style={{ padding: 20, marginBottom: 20 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 10, marginBottom: 12 }}>
        <div>
          <div className="page-label" style={{ marginBottom: 4 }}>Réservations en cours</div>
          <div style={{ fontSize: 12, color: 'var(--text-dim)' }}>
            {holds.length > 0
              ? `${holds.length} hold${holds.length > 1 ? 's' : ''} en attente de capture ou d'expiration`
              : 'Aucune réservation en attente'}
          </div>
        </div>
        <button className="btn btn-ghost" style={{ fontSize: 11 }} onClick={() => void fetchHolds()}>
          ↻ Actualiser
        </button>
      </div>

      {error && (
        <div style={{ fontSize: 12, color: 'var(--red, #ff5d73)', marginBottom: 10 }}>{error}</div>
      )}

      {loading && holds.length === 0 ? (
        <div className="shimmer-bg" style={{ height: 60, borderRadius: 12 }} />
      ) : holds.length === 0 ? (
        <div style={{ fontSize: 12, color: 'var(--text-dim)', padding: '12px 0' }}>
          Les fonds réservés (ex. paiement en cours de validation) apparaîtront ici jusqu'à leur capture ou expiration automatique.
        </div>
      ) : (
        <div className="g2" style={{ gap: 10 }}>
          {holds.map((hold) => (
            <div key={hold.operation_id} className="ib-c" style={{ padding: 12, borderRadius: 12 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                <div className="mono" style={{ fontSize: 15, color: 'var(--text-bright)' }}>
                  {formatHoldAmount(hold.amount, hold.currency)}
                </div>
                <span className="p-c pill" style={{ fontSize: 10 }}>
                  {hold.currency} · pending
                </span>
              </div>
              <div style={{ fontSize: 10, color: 'var(--text-dim)', marginTop: 6 }}>
                {hold.expires_at
                  ? `Expire dans ${formatRemaining(hold.remaining_seconds ?? 0)}`
                  : 'Sans expiration'}
                {' · '}
                <span className="mono" style={{ color: 'var(--text-dim)' }}>
                  {hold.operation_id.slice(0, 13)}…
                </span>
              </div>
              <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
                <button
                  className="btn btn-cyan"
                  style={{ fontSize: 11, padding: '6px 12px' }}
                  onClick={() => handleCapture(hold)}
                  disabled={loading}
                >
                  ✓ Capturer
                </button>
                <button
                  className="btn btn-ghost"
                  style={{ fontSize: 11, padding: '6px 12px' }}
                  onClick={() => handleRelease(hold)}
                  disabled={loading}
                >
                  ✕ Libérer
                </button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
