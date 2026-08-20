import { useCallback, useEffect, useState } from 'react';
import { motion } from 'framer-motion';
import {
  apiCardsList,
  apiCreateVirtualCard,
  type VirtualCard,
  type CardIssuanceStatus,
} from '../../api/client';
import { useAuth } from '../../context/AuthContext';
import { useDashT, localeFor } from '../../data/dashboard-i18n';
import { useI18n } from '../../context/I18nContext';
import { EASE } from '../../components/anim/Premium';
import TechLoader from '../../components/anim/TechLoader';

/** Devises supportées par Stripe Issuing (XAF non supporté). */
const CARD_CURRENCIES = ['EUR', 'USD', 'GBP'] as const;

function statusPill(status: string, t: (k: string) => string): { cls: string; label: string } {
  if (status === 'active') return { cls: 'p-gr', label: t('cards.status.active') };
  if (status === 'frozen') return { cls: 'p-c', label: t('cards.status.frozen') };
  if (status === 'cancelled') return { cls: 'p-r', label: t('cards.status.cancelled') };
  if (status === 'issuer_unavailable') return { cls: 'p-g', label: t('cards.status.issuerUnavailable') };
  return { cls: 'p-v', label: t('cards.status.pending') };
}

function VirtualCardVisual({
  name,
  currency,
  panMasked,
  brand,
}: {
  name: string;
  currency: string;
  panMasked: string;
  brand?: string | null;
}) {
  return (
    <div className="nx-vcard" aria-hidden="true">
      <div className="nx-vcard-sheen" />
      <div className="nx-vcard-top">
        <span className="nx-vcard-brand">NEXUS</span>
        <span className="nx-vcard-chip" />
      </div>
      <div className="nx-vcard-pan mono">{panMasked}</div>
      <div className="nx-vcard-bottom">
        <div>
          <div className="nx-vcard-meta">{name || '—'}</div>
          <div className="nx-vcard-meta-sub">{brand || 'VIRTUAL'}</div>
        </div>
        <div className="nx-vcard-ccy mono">{currency}</div>
      </div>
    </div>
  );
}

export default function CardsPage() {
  const t = useDashT();
  const { lang } = useI18n();
  const locale = localeFor(lang);
  const { user } = useAuth();

  const [cards, setCards] = useState<VirtualCard[]>([]);
  const [issuance, setIssuance] = useState<CardIssuanceStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const [label, setLabel] = useState('');
  const [currency, setCurrency] = useState<string>('EUR');
  const [spendLimit, setSpendLimit] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    const res = await apiCardsList();
    if (!res.success || !res.data) {
      setError(res.error || t('cards.error.load'));
      setLoading(false);
      return;
    }
    setCards(res.data.cards);
    setIssuance(res.data.issuance);
    setLoading(false);
  }, [t]);

  useEffect(() => { void load(); }, [load]);

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    setSuccess(null);
    const res = await apiCreateVirtualCard({
      label: label.trim() || undefined,
      currency,
      spend_limit: spendLimit !== '' ? Number(spendLimit) : undefined,
    });
    setSubmitting(false);
    if (!res.success || !res.data) {
      setError(res.error || t('cards.error.create'));
      return;
    }
    setSuccess(res.data.message || t('cards.success.create'));
    setLabel('');
    setSpendLimit('');
    await load();
  }

  const previewName = label.trim() || user?.full_name || 'NEXUS';

  return (
    <div className="page">
      <motion.div
        className="page-header animate-up"
        initial={{ opacity: 0, y: 18 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.45, ease: EASE }}
      >
        <div className="page-label">{t('cards.pageLabel')}</div>
        <div className="page-title">{t('cards.title')}</div>
        <p style={{ marginTop: 10, fontSize: 13, color: 'var(--text-mid)', maxWidth: 640 }}>
          {t('cards.subtitle')}
        </p>
      </motion.div>

      {error && (
        <div className="card" style={{ padding: 14, marginBottom: 16, borderColor: 'var(--danger, #e5484d)', color: 'var(--danger, #e5484d)', fontSize: 13 }} role="alert">
          {error}
        </div>
      )}
      {success && (
        <div className="card" style={{ padding: 14, marginBottom: 16, borderColor: 'var(--green)', color: 'var(--green)', fontSize: 13 }} role="status">
          {success}
        </div>
      )}

      <div className="g2" style={{ alignItems: 'start', gap: 24 }}>
        {/* ── Créer une carte virtuelle ── */}
        <motion.div
          className="card card-hi-v"
          style={{ padding: 22 }}
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.08, duration: 0.4, ease: EASE }}
        >
          <div className="page-label" style={{ marginBottom: 14 }}>{t('cards.create.section')}</div>

          <VirtualCardVisual
            name={previewName}
            currency={currency}
            panMasked="•••• •••• •••• ••••"
            brand="NEXUS"
          />

          <form onSubmit={handleCreate} style={{ marginTop: 20, display: 'flex', flexDirection: 'column', gap: 14 }}>
            <div className="form-group">
              <label className="form-label">{t('cards.create.label')}</label>
              <input
                className="input-field"
                value={label}
                onChange={(e) => setLabel(e.target.value)}
                placeholder={t('cards.create.labelPlaceholder')}
                maxLength={120}
              />
            </div>

            <div className="form-group">
              <label className="form-label">{t('cards.create.currency')}</label>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                {CARD_CURRENCIES.map((c) => (
                  <button
                    key={c}
                    type="button"
                    className={`se-chip ${currency === c ? 'se-chip-selected' : ''}`}
                    onClick={() => setCurrency(c)}
                  >
                    {c}
                  </button>
                ))}
              </div>
            </div>

            <div className="form-group">
              <label className="form-label">{t('cards.create.limit')}</label>
              <input
                className="input-field"
                type="number"
                min="0"
                step="0.01"
                value={spendLimit}
                onChange={(e) => setSpendLimit(e.target.value)}
                placeholder={t('cards.create.limitPlaceholder')}
              />
              <span className="form-hint">{t('cards.create.limitHint')}</span>
            </div>

            {issuance && !issuance.ready && (
              <div
                style={{
                  padding: '10px 12px',
                  borderRadius: 10,
                  background: 'rgba(234,184,48,0.08)',
                  border: '1px solid rgba(234,184,48,0.28)',
                  fontSize: 12,
                  color: 'var(--text-mid)',
                  lineHeight: 1.5,
                }}
              >
                {t('cards.issuance.blocked', {
                  status: issuance.status,
                  providers: issuance.providers.join(', ') || '—',
                })}
              </div>
            )}
            {issuance?.ready && (
              <div
                style={{
                  padding: '10px 12px',
                  borderRadius: 10,
                  background: 'rgba(46,204,113,0.08)',
                  border: '1px solid rgba(46,204,113,0.28)',
                  fontSize: 12,
                  color: 'var(--text-mid)',
                  lineHeight: 1.5,
                }}
              >
                {t('cards.issuance.ready', { status: issuance.status })}
              </div>
            )}

            <button type="submit" className="btn btn-cyan" disabled={submitting} style={{ width: '100%' }}>
              {submitting ? t('cards.create.submitting') : t('cards.create.submit')}
            </button>
          </form>
        </motion.div>

        {/* ── Mes cartes ── */}
        <motion.div
          className="card"
          style={{ padding: 22 }}
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.14, duration: 0.4, ease: EASE }}
        >
          <div className="page-label" style={{ marginBottom: 14 }}>{t('cards.list.section')}</div>

          {loading ? (
            <div style={{ padding: 32, textAlign: 'center' }}>
              <TechLoader label={t('common.loading')} />
            </div>
          ) : cards.length === 0 ? (
            <div style={{ padding: '28px 12px', textAlign: 'center', color: 'var(--text-dim)', fontSize: 13 }}>
              {t('cards.list.empty')}
            </div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
              {cards.map((card) => {
                const pill = statusPill(card.status, t);
                return (
                  <div
                    key={card.id}
                    style={{
                      padding: 14,
                      borderRadius: 12,
                      border: '1px solid var(--border)',
                      background: 'var(--panel2)',
                    }}
                  >
                    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, marginBottom: 10, flexWrap: 'wrap' }}>
                      <div style={{ fontWeight: 700, color: 'var(--text-bright)', fontSize: 13 }}>{card.label}</div>
                      <span className={`pill ${pill.cls}`} style={{ fontSize: 9 }}>{pill.label}</span>
                    </div>
                    <VirtualCardVisual
                      name={card.label}
                      currency={card.currency}
                      panMasked={card.pan_masked}
                      brand={card.brand}
                    />
                    <div style={{ marginTop: 10, display: 'flex', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap', fontSize: 11, color: 'var(--text-mid)' }}>
                      <span className="mono">
                        {card.spend_limit != null
                          ? t('cards.list.limit', {
                              amount: card.spend_limit.toLocaleString(locale, { minimumFractionDigits: 2 }),
                              currency: card.currency,
                            })
                          : t('cards.list.noLimit')}
                      </span>
                      <span>{new Date(card.created_at).toLocaleDateString(locale)}</span>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </motion.div>
      </div>

      <style>{`
        .nx-vcard {
          position: relative;
          width: 100%;
          max-width: 380px;
          aspect-ratio: 1.586;
          border-radius: 18px;
          padding: 22px 24px;
          background:
            radial-gradient(ellipse 80% 60% at 10% 0%, rgba(0,200,255,0.28), transparent 55%),
            radial-gradient(ellipse 70% 50% at 100% 100%, rgba(139,92,246,0.35), transparent 50%),
            linear-gradient(145deg, #0E1620 0%, #121C2A 45%, #0A1018 100%);
          border: 1px solid rgba(0,200,255,0.22);
          box-shadow: 0 18px 40px rgba(0,0,0,0.45), inset 0 1px 0 rgba(255,255,255,0.06);
          overflow: hidden;
          color: #E8F2F8;
        }
        .nx-vcard-sheen {
          position: absolute; inset: 0;
          background: linear-gradient(115deg, transparent 40%, rgba(255,255,255,0.06) 50%, transparent 60%);
          pointer-events: none;
        }
        .nx-vcard-top { display: flex; justify-content: space-between; align-items: center; position: relative; z-index: 1; }
        .nx-vcard-brand {
          font-family: var(--font-mono, ui-monospace, monospace);
          font-size: 11px; letter-spacing: 0.28em; font-weight: 700; color: rgba(0,200,255,0.95);
        }
        .nx-vcard-chip {
          width: 36px; height: 28px; border-radius: 6px;
          background: linear-gradient(135deg, #D4AF37, #B8860B 40%, #F5E6A3 70%, #C9A227);
          box-shadow: 0 2px 8px rgba(0,0,0,0.35);
        }
        .nx-vcard-pan {
          position: relative; z-index: 1;
          margin-top: 28%;
          font-size: clamp(15px, 2.4vw, 18px);
          letter-spacing: 0.18em;
          font-weight: 600;
        }
        .nx-vcard-bottom {
          position: relative; z-index: 1;
          margin-top: 18px;
          display: flex; justify-content: space-between; align-items: flex-end; gap: 12px;
        }
        .nx-vcard-meta { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; }
        .nx-vcard-meta-sub { font-size: 9px; color: rgba(184,208,224,0.65); letter-spacing: 0.12em; margin-top: 3px; }
        .nx-vcard-ccy { font-size: 14px; font-weight: 800; color: #00C8FF; }
      `}</style>
    </div>
  );
}
