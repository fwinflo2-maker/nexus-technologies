import { useState, useEffect, useCallback } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { Link } from 'react-router-dom';
import {
  apiCreateQuote,
  apiExecuteTransfer,
  type QuoteRoute,
  type QuoteData,
  type TransferTx,
} from '../../api/client';
import { useDashT, localeFor } from '../../data/dashboard-i18n';
import { useI18n } from '../../context/I18nContext';

/**
 * RouteSelectionStep — Étape du workflow /send affichant les routes
 * proposées par le Routing Engine.
 *
 * Design i-tech engine :
 *   - Pipeline d'exécution animé (chaque moteur s'allume en séquence)
 *   - Cartes de routes avec animations stagger
 *   - Compte à rebours HUD
 *   - Explication IA dynamique
 * Tous les textes visibles passent par l'i18n.
 */

// ─── Types ──────────────────────────────────────────────────────────────────

export interface SendIntent {
  action?: string;
  amount?: number;
  sourceCurrency?: string;
  originCountry?: string;
  destinationCountry?: string;
  destinationCurrency?: string;
  receivingMethod?: string;
  objective?: string;
  beneficiaryName?: string;
  beneficiaryReference?: string;
  beneficiaryOperator?: string;
}

interface RouteSelectionStepProps {
  intent: SendIntent;
  onBack: () => void;
}

// ─── Pipeline (noms techniques des moteurs, dernier libellé localisé) ───────

function formatCountdown(s: number): string {
  return `${Math.floor(s / 60)}:${(s % 60).toString().padStart(2, '0')}`;
}

const OBJECTIVE_MODES = ['optimized', 'cheapest', 'fastest', 'max_received', 'most_reliable'] as const;

// ─── Composants ─────────────────────────────────────────────────────────────

/** Panneau « sécurisation » : message utilisateur, sans révéler les moteurs internes. */
function SecureBadge({ loaded }: { loaded: boolean }) {
  const t = useDashT();
  return (
    <div className="card" style={{ padding: 16 }}>
      <div className="page-label" style={{ marginBottom: 10 }}>
        {loaded ? t('send.routes.secure.ok') : t('send.routes.secure.working')}
      </div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 12, color: 'var(--text-mid)', lineHeight: 1.5 }}>
        <span style={{ fontSize: 18 }}>🔒</span>
        <span>
          {t('send.routes.secure.text')}
        </span>
      </div>
    </div>
  );
}

/** Carte de route animée — présentation 100% utilisateur, sans mention de provider ni de mécanisme. */
function RouteCard({
  route,
  selected,
  recommended,
  onSelect,
  delay,
}: {
  route: QuoteRoute;
  selected: boolean;
  recommended: boolean;
  onSelect: () => void;
  delay: number;
}) {
  const t = useDashT();
  return (
    <motion.div
      className={`rc se-route-card ${selected ? 'selected' : ''} ${recommended ? 'se-route-recommended' : ''}`}
      initial={{ opacity: 0, y: 20, scale: 0.97 }}
      animate={{ opacity: 1, y: 0, scale: 1 }}
      transition={{ duration: 0.35, delay, ease: [0.22, 1, 0.36, 1] }}
      whileHover={{ y: -5, scale: 1.015, boxShadow: '0 14px 40px rgba(0,200,255,0.14)', transition: { type: 'spring', stiffness: 260, damping: 20 } }}
      whileTap={{ scale: 0.97 }}
      onClick={onSelect}
      style={{
        borderColor: selected ? 'var(--green)' : recommended ? 'rgba(0,200,255,0.3)' : undefined,
        boxShadow: selected ? '0 0 20px rgba(0,207,160,0.15)' : recommended ? '0 0 24px rgba(0,200,255,0.1)' : undefined,
      }}
    >
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)' }}>
          {recommended ? <span style={{ color: 'var(--green)' }}>{t('send.routes.rec.recommended')}</span> : t('send.routes.rec.option')}
        </div>
        <span className={`pill ${route.badgeCls}`} style={{ fontSize: 8 }}>{route.badge}</span>
      </div>

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end' }}>
        <div>
          <div style={{ fontSize: 10, color: 'var(--text-dim)' }}>{t('send.routes.rec.amount')}</div>
          <div style={{ fontSize: 20, fontWeight: 800, color: recommended ? 'var(--green)' : 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>
            {route.received}
          </div>
        </div>
        <div style={{ display: 'flex', gap: 20 }}>
          {([
            [t('send.routes.rec.fees'), route.fees, 'var(--text-bright)'],
            [t('send.routes.rec.delay'), route.delay, 'var(--cyan)'],
            [t('send.routes.rec.reliability'), route.reliability, route.reliabilityColor],
          ] as [string, string, string][]).map(([lbl, val, col]) => (
            <div key={lbl} style={{ textAlign: 'center' }}>
              <div style={{ fontSize: 10, color: 'var(--text-dim)' }}>{lbl}</div>
              <div style={{ fontSize: 12, fontWeight: 700, color: col }}>{val}</div>
            </div>
          ))}
        </div>
      </div>
    </motion.div>
  );
}

// ─── Composant principal ────────────────────────────────────────────────────

export default function RouteSelectionStep({ intent, onBack }: RouteSelectionStepProps) {
  const t = useDashT();
  const { lang } = useI18n();
  const locale = localeFor(lang);

  const pipeline = [
    { label: 'Intent Parser', color: 'var(--cyan)' },
    { label: 'Capability Engine', color: 'var(--cyan)' },
    { label: 'Policy Engine', color: 'var(--gold)' },
    { label: 'Quote Engine', color: 'var(--violet)' },
    { label: 'Routing Engine', color: 'var(--violet)' },
    { label: 'Optimization & Scoring', color: 'var(--gold)' },
    { label: t('send.routes.pipeline.routes'), color: 'var(--green)' },
  ];

  const modeLabel = (m: string): string => t('send.routes.mode.' + m);

  const [selected, setSelected] = useState<string>('A');
  const [quote, setQuote] = useState<QuoteData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [remaining, setRemaining] = useState(0);
  const [expired, setExpired] = useState(false);
  const [executing, setExecuting] = useState(false);
  const [executionError, setExecutionError] = useState<string | null>(null);
  const [executedTx, setExecutedTx] = useState<TransferTx | null>(null);

  // Pipeline animation
  const [pipeStep, setPipeStep] = useState(0);

  const objective = (intent.objective as string) ?? 'optimized';

  // ── Chargement quote ──────────────────────────────────
  const fetchQuote = useCallback(async () => {
    setLoading(true);
    setError(null);
    setExpired(false);
    setPipeStep(0);

    const res = await apiCreateQuote({
      amount: intent.amount ?? 0,
      sourceCurrency: intent.sourceCurrency ?? 'EUR',
      originCountry: intent.originCountry ?? '',
      destCountry: intent.destinationCountry ?? 'CG',
      destCurrency: intent.destinationCurrency ?? 'XAF',
      receivingMethod: intent.receivingMethod ?? 'mobile_money',
      objective,
    });

    if (!res.success || !res.data) {
      setError(res.error || t('send.routes.error.fetch'));
      setLoading(false);
      return;
    }

    setQuote(res.data);
    const expiresTs = new Date(res.data.expires_at).getTime() / 1000;
    const nowTs = Date.now() / 1000;
    setRemaining(Math.max(0, Math.floor(expiresTs - nowTs)));
    setLoading(false);
  }, [intent.amount, intent.sourceCurrency, intent.originCountry, intent.destinationCountry, intent.destinationCurrency, intent.receivingMethod, objective, t]);

  useEffect(() => { fetchQuote(); }, [fetchQuote]);

  // ── Pipeline animation ──────────────────────────────────
  useEffect(() => {
    if (!loading) return;
    setPipeStep(0);
    const interval = setInterval(() => {
      setPipeStep(prev => {
        if (prev >= pipeline.length) { clearInterval(interval); return prev; }
        return prev + 1;
      });
    }, 280);
    return () => clearInterval(interval);
  }, [loading, pipeline.length]);

  // ── Compte à rebours ────────────────────────────────────
  useEffect(() => {
    if (remaining <= 0 || loading) return;
    const timer = setInterval(() => {
      setRemaining(prev => { if (prev <= 1) { clearInterval(timer); setExpired(true); return 0; } return prev - 1; });
    }, 1000);
    return () => clearInterval(timer);
  }, [remaining, loading, quote?.id]);

  const routes: QuoteRoute[] = quote?.routes ?? [];
  const selectedRoute = routes.find(r => r.id === selected) ?? routes[0];
  const amountSent = intent.amount ?? 0;

  const handleConfirm = async () => {
    if (expired || !quote || !selectedRoute || executing) return;
    setExecuting(true);
    setExecutionError(null);

    const idemKey = typeof crypto !== 'undefined' && 'randomUUID' in crypto
      ? crypto.randomUUID()
      : `${Date.now()}-${Math.random().toString(16).slice(2)}`;

    const res = await apiExecuteTransfer({
      quote_id: quote.id,
      route_id: selectedRoute.id,
      idempotency_key: idemKey,
    });

    setExecuting(false);

    if (res.success && res.data) {
      setExecutedTx(res.data);
    } else {
      setExecutionError(res.error || t('send.routes.exec.fetchError'));
    }
  };

  // ── Countdown color ────────────────────────────────────
  const countdownColor = remaining > 120 ? 'var(--green)' : remaining > 60 ? 'var(--gold)' : 'var(--red)';

  // ── États ──────────────────────────────────────────────

  if (loading && !quote) {
    return (
      <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
        {/* Header */}
        <div className="se-badge" style={{ width: 'fit-content' }}><span className="se-led" /> ROUTING ENGINE // ANALYSE EN COURS</div>
        <div className="se-layout">
          <div className="se-main">
            {/* Boot screen */}
            <div className="card card-hi-c" style={{ padding: 30 }}>
              <div className="se-boot">
                <div className="se-boot-ring"><div className="se-boot-core" /></div>
                <div className="se-boot-log">{t('send.routes.bootSearch')}</div>
              </div>
              {/* Progress bar */}
              <div style={{ marginTop: 16 }}>
                <div className="progress-bar-bg" style={{ height: 4, borderRadius: 2 }}>
                  <motion.div
                    className="progress-bar"
                    style={{ background: 'linear-gradient(90deg, var(--cyan), var(--violet))' }}
                    initial={{ width: '0%' }}
                    animate={{ width: `${Math.min(100, (pipeStep / pipeline.length) * 100)}%` }}
                    transition={{ duration: 0.3, ease: 'easeOut' }}
                  />
                </div>
                <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 6 }}>
                  <span style={{ fontSize: 9, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>
                    {pipeStep < pipeline.length ? t('send.routes.optimizing') : t('send.routes.done')}
                  </span>
                </div>
              </div>
            </div>
          </div>
          <aside className="se-rail">
            <SecureBadge loaded={false} />
          </aside>
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: 300 }}>
        <div className="card card-hi-c" style={{ padding: 40, textAlign: 'center', maxWidth: 500 }}>
          <div style={{ fontSize: 28, marginBottom: 12 }}>⚠️</div>
          <h2 style={{ color: 'var(--text-bright)', marginBottom: 10, fontSize: 18 }}>{t('send.routes.error.title')}</h2>
          <p style={{ color: 'var(--text-mid)', marginBottom: 20, fontSize: 13, lineHeight: 1.6 }}>{error}</p>
          <div style={{ display: 'flex', gap: 8, justifyContent: 'center' }}>
            <button className="se-cta" onClick={fetchQuote}>{t('send.routes.error.retry')}</button>
            <button className="se-cta se-cta-ghost" onClick={onBack}>{t('send.routes.error.back')}</button>
          </div>
        </div>
      </div>
    );
  }

  if (expired) {
    return (
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: 300 }}>
        <div className="card card-hi-g" style={{ padding: 40, textAlign: 'center', maxWidth: 500 }}>
          <div style={{ fontSize: 28, marginBottom: 12 }}>⏱️</div>
          <h2 style={{ color: 'var(--gold)', marginBottom: 10, fontSize: 18 }}>{t('send.routes.expired.title')}</h2>
          <p style={{ color: 'var(--text-mid)', marginBottom: 8, fontSize: 13, lineHeight: 1.6 }}>
            {t('send.routes.expired.text')}
          </p>
          <p style={{ color: 'var(--text-dim)', marginBottom: 20, fontSize: 11 }}>
            {t('send.routes.expired.text2')}
          </p>
          <div style={{ display: 'flex', gap: 8, justifyContent: 'center' }}>
            <button className="se-cta" onClick={fetchQuote}>{t('send.routes.expired.new')}</button>
            <button className="se-cta se-cta-ghost" onClick={onBack}>{t('send.routes.error.back')}</button>
          </div>
        </div>
      </div>
    );
  }

  // ── Succès : transaction exécutée ─────────────────────────
  if (executedTx) {
    return (
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: 400 }}>
        <div className="card card-hi-gr" style={{ padding: 40, textAlign: 'center', maxWidth: 560 }}>
          <div style={{ fontSize: 34, marginBottom: 12 }}>✅</div>
          <h2 style={{ color: 'var(--green)', marginBottom: 10, fontSize: 20 }}>{t('send.routes.success.title')}</h2>
          <p style={{ color: 'var(--text-mid)', marginBottom: 20, fontSize: 13, lineHeight: 1.6 }}>
            {t('send.routes.success.text')}
          </p>
          <div className="card" style={{ padding: 16, textAlign: 'left', marginBottom: 20 }}>
            {([
              [t('send.routes.success.ref'), `#${executedTx.id}`],
              [t('send.routes.success.status'), executedTx.status === 'completed' ? t('send.routes.success.done') : t('status.' + executedTx.status)],
              [t('send.routes.success.sent'), `${executedTx.amount.toLocaleString(locale)} ${executedTx.currency}`],
              [t('send.routes.success.fees'), executedTx.fee != null ? `${executedTx.fee.toLocaleString(locale)} ${executedTx.fee_currency ?? executedTx.currency}` : '—'],
              [t('send.routes.success.received'), executedTx.dest_amount != null ? `${executedTx.dest_amount.toLocaleString(locale)} ${executedTx.dest_currency ?? ''}` : '—'],
              [t('send.routes.success.fx'), executedTx.fx_rate != null ? executedTx.fx_rate.toLocaleString(locale, { maximumFractionDigits: 4 }) : '—'],
              [t('send.routes.success.route'), executedTx.route_id ?? '—'],
              [t('send.routes.success.dest'), executedTx.destination ?? '—'],
            ] as [string, string][]).map(([k, v]) => (
              <div key={k} style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid var(--border)' }}>
                <span style={{ fontSize: 11, color: 'var(--text-dim)' }}>{k}</span>
                <span style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>{v}</span>
              </div>
            ))}
          </div>
          <div style={{ display: 'flex', gap: 8, justifyContent: 'center' }}>
            <Link to="/history" className="se-cta">{t('send.routes.success.history')}</Link>
            <Link to="/send" className="se-cta se-cta-ghost">{t('send.routes.success.new')}</Link>
          </div>
        </div>
      </div>
    );
  }

  // ── Erreur d'exécution ────────────────────────────────────
  if (executionError) {
    return (
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: 400 }}>
        <div className="card card-hi-g" style={{ padding: 40, textAlign: 'center', maxWidth: 520 }}>
          <div style={{ fontSize: 30, marginBottom: 12 }}>⚠️</div>
          <h2 style={{ color: 'var(--gold)', marginBottom: 10, fontSize: 18 }}>{t('send.routes.exec.title')}</h2>
          <p style={{ color: 'var(--text-mid)', marginBottom: 20, fontSize: 13, lineHeight: 1.6 }}>{executionError}</p>
          <div style={{ display: 'flex', gap: 8, justifyContent: 'center' }}>
            <button className="se-cta" onClick={handleConfirm}>{t('send.routes.exec.retry')}</button>
            <button className="se-cta se-cta-ghost" onClick={onBack}>{t('send.routes.exec.back')}</button>
          </div>
        </div>
      </div>
    );
  }

  // ── Rendu principal ──────────────────────────────────────

  return (
    <motion.div className="se-layout" initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ duration: 0.4 }}>

      {/* ═══ Colonne gauche ══════════════════════════════════════════════ */}
      <div className="se-main">

        {/* Récap intention */}
        <motion.div className="card card-hi-c" style={{ padding: 20 }} initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.1 }}>
          <div className="page-label" style={{ marginBottom: 12 }}>{t('send.routes.recap.title')}</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
              <div>
                <div className="se-field-label">{t('send.routes.recap.from')}</div>
                <div style={{ padding: '8px 10px', background: 'var(--panel2)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12, color: 'var(--text-bright)' }}>
                  {intent.originCountry ?? '—'} · {intent.sourceCurrency ?? 'EUR'}
                </div>
              </div>
              <div>
                <div className="se-field-label">{t('send.routes.recap.to')}</div>
                <div style={{ padding: '8px 10px', background: 'var(--panel2)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12, color: 'var(--text-bright)' }}>
                  {intent.destinationCurrency ?? 'XAF'} · {intent.destinationCountry ?? 'CG'}
                </div>
              </div>
            </div>
            <div>
              <div className="se-field-label">{t('send.routes.recap.amount')}</div>
              <div className="se-amount" style={{ borderRadius: 8 }}>
                <span style={{ padding: '8px 12px', color: 'var(--cyan)', fontWeight: 700, fontSize: 13, borderRight: '1px solid var(--border)' }}>€</span>
                <span style={{ flex: 1, padding: '8px 10px', color: 'var(--white)', fontSize: 14, fontWeight: 700, fontFamily: 'var(--font-mono)' }}>
                  {amountSent.toLocaleString(locale)},00
                </span>
              </div>
            </div>
            <div>
              <div className="se-field-label">{t('send.routes.recap.mode')}</div>
              <div style={{ display: 'flex', gap: 5, flexWrap: 'wrap' as const }}>
                {OBJECTIVE_MODES.map(m => (
                  <span
                    key={m}
                    className={`pill ${m === objective ? 'p-c' : ''}`}
                    style={{
                      background: m === objective ? 'rgba(0,200,255,0.1)' : 'var(--panel2)',
                      borderColor: m === objective ? 'rgba(0,200,255,0.35)' : 'var(--border)',
                      color: m === objective ? 'var(--cyan)' : 'var(--text-dim)',
                      fontSize: 9, cursor: 'default',
                    }}
                  >
                    {m === 'optimized' && '⭐ '}{m === 'fastest' && '⚡ '}{m === 'max_received' && '💰 '}{m === 'cheapest' && '💸 '}{m === 'most_reliable' && '🛡️ '}
                    {modeLabel(m)}
                  </span>
                ))}
              </div>
            </div>
          </div>
        </motion.div>

        {/* Pipeline */}
        <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.2 }}>
          <SecureBadge loaded={true} />
        </motion.div>
      </div>

      {/* ═══ Colonne droite ══════════════════════════════════════════════ */}
      <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>

        {/* Header routes + countdown */}
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <div className="page-label">{t('send.routes.options.title')}</div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
              <span style={{ fontSize: 9, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>⏱</span>
              <span style={{ fontSize: 13, fontWeight: 700, color: countdownColor, fontFamily: 'var(--font-mono)', fontVariantNumeric: 'tabular-nums' }}>
                {formatCountdown(remaining)}
              </span>
            </div>
            <button className="se-cta se-cta-ghost" onClick={fetchQuote} disabled={loading} style={{ fontSize: 9, padding: '4px 10px' }}>
              {t('send.routes.options.recalc')}
            </button>
          </div>
        </div>

        {/* Cartes de routes */}
        <AnimatePresence>
          {routes.map((r, i) => (
            <RouteCard
              key={r.id}
              route={r}
              selected={selected === r.id}
              recommended={r.recommended}
              onSelect={() => setSelected(r.id)}
              delay={0.15 + i * 0.1}
            />
          ))}
        </AnimatePresence>

        {/* Récap route sélectionnée */}
        {selectedRoute && (
          <motion.div
            className="card card-hi-c"
            style={{ marginTop: 4 }}
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.5 }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', paddingBottom: 12, borderBottom: '1px solid var(--border)' }}>
              <div>
                <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)' }}>
                  {t('send.routes.sel.title')}
                </div>
                <div style={{ fontSize: 10, color: 'var(--text-dim)', marginTop: 2 }}>
                  {t('send.routes.sel.sub')}
                </div>
              </div>
                          </div>
            <div style={{ marginTop: 10 }}>
              {[
                [t('send.routes.sel.sent'), `\u20AC ${amountSent.toLocaleString(locale)},00`, 'var(--white)'],
                [t('send.routes.sel.received'), selectedRoute.received, 'var(--green)'],
                [t('send.routes.sel.fees'), selectedRoute.fees, 'var(--text-bright)'],
                [t('send.routes.sel.rate'), selectedRoute.rate ? `1 ${intent.sourceCurrency ?? 'EUR'} = ${selectedRoute.rate.toLocaleString(locale)} ${intent.destinationCurrency ?? ''}` : '—', 'var(--cyan)'],
                [t('send.routes.sel.delay'), selectedRoute.delay, 'var(--cyan)'],
                [t('send.routes.sel.expiry'), `\u23F1 ${t('send.routes.sel.expiryCount', { time: formatCountdown(remaining) })}`, countdownColor],
              ].map(([k, v, col]) => (
                <div key={k as string} className="trow">
                  <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>{k}</div>
                  <div style={{ fontSize: 12, fontWeight: 700, color: col as string, fontFamily: 'var(--font-mono)' }}>{v}</div>
                </div>
              ))}
            </div>
            <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
              <button className="se-cta se-cta-ghost" onClick={onBack}>{t('send.routes.sel.edit')}</button>
              <button className="se-cta" onClick={handleConfirm} disabled={executing || expired} style={{ flex: 1, opacity: executing || expired ? 0.55 : 1 }}>
                {executing ? t('send.routes.sel.executing') : t('send.routes.sel.confirm')}
              </button>
            </div>
          </motion.div>
        )}

        {/* AI Explanation */}
        <motion.div className="card card-hi-v" initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.6 }}>
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 8 }}>
            <div className="ib ib-v" style={{ width: 26, height: 26, borderRadius: 6, fontSize: 12 }}>🤖</div>
            <span style={{ fontSize: 9, color: 'var(--violet)', fontFamily: 'var(--font-mono)', letterSpacing: '0.12em' }}>NEXUS AI</span>
          </div>
          <div className="quote quote-v" style={{ padding: '10px 14px' }}>
            <div className="quote-text" style={{ fontSize: 11, fontStyle: 'normal', fontWeight: 500 }}>
              {selectedRoute ? (
                <>{t('send.routes.ai.text', { mode: modeLabel(objective), reliability: (selectedRoute.reliabilityNum * 100).toFixed(0) })}</>
              ) : (
                <>{t('send.routes.ai.analyzing')}</>
              )}
            </div>
          </div>
        </motion.div>
      </div>
    </motion.div>
  );
}
