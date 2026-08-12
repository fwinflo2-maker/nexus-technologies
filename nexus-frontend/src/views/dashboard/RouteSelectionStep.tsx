import { useState, useEffect, useCallback } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import {
  apiCreateQuote,
  type QuoteRoute,
  type QuoteData,
} from '../../api/client';

/**
 * RouteSelectionStep — Étape du workflow /send affichant les routes
 * proposées par le Routing Engine.
 *
 * Design i-tech engine :
 *   - Pipeline d'exécution animé (chaque moteur s'allume en séquence)
 *   - Cartes de routes avec animations stagger
 *   - Compte à rebours HUD
 *   - Explication IA dynamique
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

// ─── Pipeline ───────────────────────────────────────────────────────────────

const PIPELINE = [
  { label: 'Intent Parser',         color: 'var(--cyan)' },
  { label: 'Capability Engine',     color: 'var(--cyan)' },
  { label: 'Policy Engine',         color: 'var(--gold)' },
  { label: 'Quote Engine',          color: 'var(--violet)' },
  { label: 'Routing Engine',        color: 'var(--violet)' },
  { label: 'Optimization & Scoring', color: 'var(--gold)' },
  { label: 'Routes présentées',     color: 'var(--green)' },
];

const MODE_LABELS: Record<string, string> = {
  optimized: 'optimisé', fastest: 'plus rapide',
  max_received: 'max reçu', cheapest: 'moins cher', most_reliable: 'plus fiable',
};

function formatCountdown(s: number): string {
  return `${Math.floor(s / 60)}:${(s % 60).toString().padStart(2, '0')}`;
}

// ─── Composants ─────────────────────────────────────────────────────────────

/** Pipeline animé — chaque étape s'allume en séquence pendant le chargement. */
function EnginePipeline({ loaded, activeCount }: { loaded: boolean; activeCount: number }) {
  const displayedCount = loaded ? PIPELINE.length : activeCount;
  return (
    <div className="card" style={{ padding: 18 }}>
      <div className="page-label" style={{ marginBottom: 12 }}>
        Pipeline d'exécution
        {!loaded && <span style={{ color: 'var(--cyan)', marginLeft: 8 }}>● EN COURS</span>}
        {loaded && <span style={{ color: 'var(--green)', marginLeft: 8 }}>✓ TERMINÉ</span>}
      </div>
      <div className="se-pipeline">
        {PIPELINE.map((s, i) => {
          const done = i < displayedCount - (loaded ? 0 : 0);
          const isActive = !loaded && i === displayedCount;
          return (
            <div key={i}>
              <motion.div
                className={`se-pipe-step ${isActive ? 'se-pipe-active' : ''} ${done && displayedCount >= PIPELINE.length ? 'se-pipe-done' : ''}`}
                initial={{ opacity: 0, x: -10 }}
                animate={{ opacity: 1, x: 0 }}
                transition={{ duration: 0.25, delay: loaded ? 0 : i * 0.18 }}
              >
                <div className="se-pipe-dot" />
                <span className="se-pipe-name">{s.label}</span>
                <span className="se-pipe-state" style={{ color: done && displayedCount >= PIPELINE.length ? s.color : undefined }}>
                  {done && displayedCount >= PIPELINE.length ? '✓' : isActive ? '●' : '○'}
                </span>
              </motion.div>
              {i < PIPELINE.length - 1 && (
                <div className={`se-pipe-conn ${done && displayedCount >= PIPELINE.length ? 'se-pipe-conn-done' : ''}`} />
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}

/** Carte de route animée. */
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
  return (
    <motion.div
      className={`rc se-route-card ${selected ? 'selected' : ''} ${recommended ? 'se-route-recommended' : ''}`}
      initial={{ opacity: 0, y: 20, scale: 0.97 }}
      animate={{ opacity: 1, y: 0, scale: 1 }}
      transition={{ duration: 0.35, delay, ease: [0.22, 1, 0.36, 1] }}
      whileHover={{ y: -2, transition: { duration: 0.15 } }}
      whileTap={{ scale: 0.98 }}
      onClick={onSelect}
      style={{
        borderColor: selected ? 'var(--green)' : recommended ? 'rgba(0,200,255,0.3)' : undefined,
        boxShadow: selected ? '0 0 20px rgba(0,207,160,0.15)' : recommended ? '0 0 24px rgba(0,200,255,0.1)' : undefined,
      }}
    >
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)' }}>
          ROUTE {route.id}
          {recommended && <span style={{ fontSize: 9, color: 'var(--cyan)', marginLeft: 6 }}>(recommandée)</span>}
        </div>
        <span className={`pill ${route.badgeCls}`} style={{ fontSize: 8 }}>{route.badge}</span>
      </div>

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end' }}>
        <div>
          <div style={{ fontSize: 10, color: 'var(--text-dim)' }}>Montant reçu</div>
          <div style={{ fontSize: 20, fontWeight: 800, color: recommended ? 'var(--green)' : 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>
            {route.received}
          </div>
        </div>
        <div style={{ display: 'flex', gap: 20 }}>
          {([
            ['Frais', route.fees, 'var(--text-bright)'],
            ['Délai', route.delay, 'var(--cyan)'],
            ['Fiabilité', route.reliability, route.reliabilityColor],
          ] as [string, string, string][]).map(([lbl, val, col]) => (
            <div key={lbl} style={{ textAlign: 'center' }}>
              <div style={{ fontSize: 10, color: 'var(--text-dim)' }}>{lbl}</div>
              <div style={{ fontSize: 12, fontWeight: 700, color: col }}>{val}</div>
            </div>
          ))}
        </div>
      </div>

      <div style={{ marginTop: 10, display: 'flex', gap: 8, alignItems: 'center' }}>
        <span className="mono" style={{ fontSize: 10, color: 'var(--text-dim)' }}>{route.provider}</span>
        <span style={{ fontSize: 10, color: 'var(--text-mid)' }}>·</span>
        <span style={{ fontSize: 11, fontWeight: 600, color: 'var(--text-bright)' }}>{route.method}</span>
        <span style={{ marginLeft: 'auto', fontSize: 9, color: 'var(--text-dim)' }}>spread {route.spread}</span>
      </div>
    </motion.div>
  );
}

// ─── Composant principal ────────────────────────────────────────────────────

export default function RouteSelectionStep({ intent, onBack }: RouteSelectionStepProps) {
  const [selected, setSelected] = useState<string>('A');
  const [quote, setQuote] = useState<QuoteData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [remaining, setRemaining] = useState(0);
  const [expired, setExpired] = useState(false);

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
      setError(res.error || 'Erreur lors du calcul des routes.');
      setLoading(false);
      return;
    }

    setQuote(res.data);
    const expiresTs = new Date(res.data.expires_at).getTime() / 1000;
    const nowTs = Date.now() / 1000;
    setRemaining(Math.max(0, Math.floor(expiresTs - nowTs)));
    setLoading(false);
  }, [intent.amount, intent.sourceCurrency, intent.originCountry, intent.destinationCountry, intent.destinationCurrency, intent.receivingMethod, objective]);

  useEffect(() => { fetchQuote(); }, [fetchQuote]);

  // ── Pipeline animation ──────────────────────────────────
  useEffect(() => {
    if (!loading) return;
    setPipeStep(0);
    const interval = setInterval(() => {
      setPipeStep(prev => {
        if (prev >= PIPELINE.length) { clearInterval(interval); return prev; }
        return prev + 1;
      });
    }, 280);
    return () => clearInterval(interval);
  }, [loading]);

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

  const handleConfirm = () => {
    if (expired || !quote || !selectedRoute) return;
    sessionStorage.setItem('nexus_selected_route', JSON.stringify({ quoteId: quote.id, routeId: selectedRoute.id, route: selectedRoute, intent: quote.intent }));
    alert('Route sélectionnée ! L\'étape Confirmation & Exécution sera implémentée dans la prochaine itération.');
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
                <div className="se-boot-log">Analyse des corridors et calcul des routes…</div>
              </div>
              {/* Progress bar */}
              <div style={{ marginTop: 16 }}>
                <div className="progress-bar-bg" style={{ height: 4, borderRadius: 2 }}>
                  <motion.div
                    className="progress-bar"
                    style={{ background: 'linear-gradient(90deg, var(--cyan), var(--violet))' }}
                    initial={{ width: '0%' }}
                    animate={{ width: `${Math.min(100, (pipeStep / PIPELINE.length) * 100)}%` }}
                    transition={{ duration: 0.3, ease: 'easeOut' }}
                  />
                </div>
                <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 6 }}>
                  <span style={{ fontSize: 9, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>
                    Étape {Math.min(pipeStep, PIPELINE.length)}/{PIPELINE.length}
                  </span>
                  <span style={{ fontSize: 9, color: 'var(--cyan)', fontFamily: 'var(--font-mono)' }}>
                    {pipeStep < PIPELINE.length ? PIPELINE[Math.min(pipeStep, PIPELINE.length - 1)]?.label : 'Terminé'}
                  </span>
                </div>
              </div>
            </div>
          </div>
          <aside className="se-rail">
            <EnginePipeline loaded={false} activeCount={pipeStep} />
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
          <h2 style={{ color: 'var(--text-bright)', marginBottom: 10, fontSize: 18 }}>Moteur de routing en erreur</h2>
          <p style={{ color: 'var(--text-mid)', marginBottom: 20, fontSize: 13, lineHeight: 1.6 }}>{error}</p>
          <div style={{ display: 'flex', gap: 8, justifyContent: 'center' }}>
            <button className="se-cta" onClick={fetchQuote}>↻ Relancer le moteur</button>
            <button className="se-cta se-cta-ghost" onClick={onBack}>← Retour au résumé</button>
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
          <h2 style={{ color: 'var(--gold)', marginBottom: 10, fontSize: 18 }}>Quote expirée</h2>
          <p style={{ color: 'var(--text-mid)', marginBottom: 8, fontSize: 13, lineHeight: 1.6 }}>
            Cette quote a expiré après 5 minutes. Les prix et taux peuvent avoir changé.
          </p>
          <p style={{ color: 'var(--text-dim)', marginBottom: 20, fontSize: 11 }}>
            Relancez une demande depuis le formulaire d'envoi pour obtenir de nouvelles routes.
          </p>
          <div style={{ display: 'flex', gap: 8, justifyContent: 'center' }}>
            <button className="se-cta" onClick={fetchQuote}>↻ Nouvelles routes</button>
            <button className="se-cta se-cta-ghost" onClick={onBack}>← Retour au résumé</button>
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
          <div className="page-label" style={{ marginBottom: 12 }}>Intention validée</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
              <div>
                <div className="se-field-label">Depuis</div>
                <div style={{ padding: '8px 10px', background: 'var(--panel2)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12, color: 'var(--text-bright)' }}>
                  {intent.originCountry ?? '—'} · {intent.sourceCurrency ?? 'EUR'}
                </div>
              </div>
              <div>
                <div className="se-field-label">Vers</div>
                <div style={{ padding: '8px 10px', background: 'var(--panel2)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12, color: 'var(--text-bright)' }}>
                  {intent.destinationCurrency ?? 'XAF'} · {intent.destinationCountry ?? 'CG'}
                </div>
              </div>
            </div>
            <div>
              <div className="se-field-label">Montant</div>
              <div className="se-amount" style={{ borderRadius: 8 }}>
                <span style={{ padding: '8px 12px', color: 'var(--cyan)', fontWeight: 700, fontSize: 13, borderRight: '1px solid var(--border)' }}>€</span>
                <span style={{ flex: 1, padding: '8px 10px', color: 'var(--white)', fontSize: 14, fontWeight: 700, fontFamily: 'var(--font-mono)' }}>
                  {amountSent.toLocaleString('fr-FR')},00
                </span>
              </div>
            </div>
            <div>
              <div className="se-field-label">Mode d'analyse</div>
              <div style={{ display: 'flex', gap: 5, flexWrap: 'wrap' as const }}>
                {(['optimized', 'cheapest', 'fastest', 'max_received', 'most_reliable'] as const).map(m => (
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
                    {MODE_LABELS[m]}
                  </span>
                ))}
              </div>
            </div>
          </div>
        </motion.div>

        {/* Pipeline */}
        <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.2 }}>
          <EnginePipeline loaded={true} activeCount={PIPELINE.length} />
        </motion.div>
      </div>

      {/* ═══ Colonne droite ══════════════════════════════════════════════ */}
      <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>

        {/* Header routes + countdown */}
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <div className="page-label">Routes disponibles</div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
              <span style={{ fontSize: 9, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>⏱</span>
              <span style={{ fontSize: 13, fontWeight: 700, color: countdownColor, fontFamily: 'var(--font-mono)', fontVariantNumeric: 'tabular-nums' }}>
                {formatCountdown(remaining)}
              </span>
            </div>
            <button className="se-cta se-cta-ghost" onClick={fetchQuote} disabled={loading} style={{ fontSize: 9, padding: '4px 10px' }}>
              ⟴ Recalculer
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
              <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)' }}>
                Route {selected} sélectionnée
              </div>
              <div className="mono" style={{ fontSize: 10, color: 'var(--text-dim)' }}>Quote #{quote?.id ?? '—'}</div>
            </div>
            <div style={{ marginTop: 10 }}>
              {[
                ['Envoyé', `\u20AC ${amountSent.toLocaleString('fr-FR')},00`, 'var(--white)'],
                ['Reçu (estimé)', selectedRoute.received, 'var(--green)'],
                ['Frais total', selectedRoute.fees, 'var(--text-bright)'],
                ['Provider', selectedRoute.provider, 'var(--cyan)'],
                ['Méthode', selectedRoute.method, 'var(--text-bright)'],
                ['Expiration', `\u23F1 ${formatCountdown(remaining)} restantes`, countdownColor],
              ].map(([k, v, col]) => (
                <div key={k as string} className="trow">
                  <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>{k}</div>
                  <div style={{ fontSize: 12, fontWeight: 700, color: col as string, fontFamily: 'var(--font-mono)' }}>{v}</div>
                </div>
              ))}
            </div>
            <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
              <button className="se-cta se-cta-ghost" onClick={onBack}>← Modifier</button>
              <button className="se-cta" onClick={handleConfirm} style={{ flex: 1 }}>✓ Confirmer et exécuter</button>
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
                <>La Route {selected} est recommandée pour le mode «{MODE_LABELS[objective] ?? objective}». Spread de {selectedRoute.spread}, fiabilité historique {(selectedRoute.reliabilityNum * 100).toFixed(0)}%. Les moteurs déterministes vérifient — vous décidez.</>
              ) : (
                <>Analyse des routes en cours…</>
              )}
            </div>
          </div>
        </motion.div>
      </div>
    </motion.div>
  );
}
