import { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { EASE } from '../../components/anim/Premium';
import {
  apiIntentCoverage,
  apiAuthorizedOrigins,
  estimateConvert,
  apiWalletsList,
  type WalletState,
  type IntentCoverageData,
  type IntentCountry,
  type AuthorizedOriginsData,
} from '../../api/client';
import RouteSelectionStep from './RouteSelectionStep';
import { useDashT, localeFor } from '../../data/dashboard-i18n';
import { useI18n } from '../../context/I18nContext';

/**
 * SendPage — Moteur de transfert NEXUS (i-tech engine).
 *
 * Flux conforme à la philosophie NEXUS :
 *   ÉLIGIBILITÉ → CONFIRMATION → ROUTING
 *
 * L'onglet "Envoyer" ne traique QUE les envois sortants.
 * Recharger / Recevoir sont des opérations du Wallet.
 *
 * Concepts strictement séparés :
 *   - Pays de résidence (KYC) → source user, non modifiable dans /send
 *   - Origine des fonds → dérivée des sources de financement vérifiées
 *   - Destination → couverture providers
 *
 * Le frontend ne décide JAMAIS des origines disponibles.
 */

// ─── Types ──────────────────────────────────────────────────────────────────

type Step = 1 | 2 | 3;

interface BeneficiaryInfo {
  name: string;
  reference: string;
  secondaryRef: string;
  operator: string;
}

interface FormErrors {
  amount?: string;
  sourceCurrency?: string;
  originCountry?: string;
  destCountry?: string;
  destCurrency?: string;
  receivingMethod?: string;
  beneficiaryName?: string;
  beneficiaryRef?: string;
}

// ─── Constantes ─────────────────────────────────────────────────────────────

const OBJECTIVE_OPTIONS: Array<{ key: string; icon: string; i18nLabel: string; i18nDesc: string }> = [
  { key: 'optimized',     icon: '⭐', i18nLabel: 'send.obj.optimized',     i18nDesc: 'send.obj.optimized.desc' },
  { key: 'fastest',       icon: '⚡', i18nLabel: 'send.obj.fastest',       i18nDesc: 'send.obj.fastest.desc' },
  { key: 'cheapest',      icon: '💸', i18nLabel: 'send.obj.cheapest',      i18nDesc: 'send.obj.cheapest.desc' },
  { key: 'max_received',  icon: '💰', i18nLabel: 'send.obj.max_received',  i18nDesc: 'send.obj.max_received.desc' },
  { key: 'most_reliable', icon: '🛡️', i18nLabel: 'send.obj.most_reliable', i18nDesc: 'send.obj.most_reliable.desc' },
];

const STEPS: Array<{ num: Step; i18nLabel: string; i18nSub: string }> = [
  { num: 1, i18nLabel: 'send.step1', i18nSub: 'send.step1.sub' },
  { num: 2, i18nLabel: 'send.step2', i18nSub: 'send.step2.sub' },
  { num: 3, i18nLabel: 'send.step3', i18nSub: 'send.step3.sub' },
];

// ─── Helpers ────────────────────────────────────────────────────────────────

function beneficiaryFieldsForMethod(methodType: string, t: (key: string, params?: Record<string, string | number>) => string): {
  nameLabel: string; refLabel: string; refPlaceholder: string;
  secondaryLabel?: string; secondaryPlaceholder?: string;
} {
  switch (methodType) {
    case 'mobile_money': return { nameLabel: t('send.beneficiary.name'), refLabel: t('send.beneficiary.phone'), refPlaceholder: t('send.beneficiary.phonePlaceholder'), secondaryLabel: t('send.beneficiary.operator') };
    case 'bank':         return { nameLabel: t('send.beneficiary.name'), refLabel: t('send.beneficiary.iban'), refPlaceholder: t('send.beneficiary.ibanPlaceholder') };
    case 'crypto':       return { nameLabel: t('send.beneficiary.pseudo'), refLabel: t('send.beneficiary.wallet'), refPlaceholder: t('send.beneficiary.walletPlaceholder'), secondaryLabel: t('send.beneficiary.network') };
    case 'cash_pickup':  return { nameLabel: t('send.beneficiary.name'), refLabel: t('send.beneficiary.withdrawInfo'), refPlaceholder: t('send.beneficiary.withdrawPlaceholder'), secondaryLabel: t('send.beneficiary.city'), secondaryPlaceholder: t('send.beneficiary.cityPlaceholder') };
    default:             return { nameLabel: t('send.beneficiary.name'), refLabel: t('send.beneficiary.ref'), refPlaceholder: '' };
  }
}

function formatCurrencyDisplay(val: number, cur: string, locale: string): string {
  const formatted = val.toLocaleString(locale, {
    minimumFractionDigits: ['XAF', 'XOF', 'NGN', 'GHS', 'UGX', 'TZS', 'RWF', 'ZMW', 'IDR', 'JPY', 'CLP', 'COP', 'ARS', 'HUF', 'GNF'].includes(cur) ? 0 : 2,
    maximumFractionDigits: 2,
  });
  return `${formatted} ${cur}`;
}

// ─── Animations (framer-motion) ─────────────────────────────────────────────

const BEZIER: [number, number, number, number] = [0.22, 1, 0.36, 1];

const fadeSlideUp = {
  initial: { opacity: 0, y: 24, scale: 0.98 },
  animate: { opacity: 1, y: 0, scale: 1 },
  exit:    { opacity: 0, y: -20, scale: 0.98 },
  transition: { duration: 0.35, ease: BEZIER },
};

const stagger = (i: number, base = 0.06) => ({
  initial: { opacity: 0, y: 14 },
  animate: { opacity: 1, y: 0 },
  transition: { duration: 0.3, delay: i * base, ease: BEZIER },
});

// ─── Sous-composants ────────────────────────────────────────────────────────

/** Compteur animé — chiffre qui évolue en douceur. */
function AnimatedValue({ value, format }: { value: number; format: (v: number) => string }) {
  const ref = useRef<HTMLSpanElement>(null);
  const prev = useRef(value);

  useEffect(() => {
    const from = prev.current;
    const to = value;
    if (from === to) { if (ref.current) ref.current.textContent = format(to); return; }
    const start = performance.now();
    const dur = 420;
    let raf: number;
    const tick = (t: number) => {
      const p = Math.min(1, (t - start) / dur);
      const eased = 1 - Math.pow(1 - p, 3);
      const cur = from + (to - from) * eased;
      if (ref.current) ref.current.textContent = format(cur);
      if (p < 1) raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(raf);
  }, [value, format]);

  return <span ref={ref}>{format(prev.current)}</span>;
}

/** Rail d'étapes animé. */
function StepRail({ current, completed }: { current: Step; completed: Step[] }) {
  const t = useDashT();
  return (
    <div className="se-railbar">
      {STEPS.map((s, i) => {
        const done = completed.includes(s.num);
        const active = s.num === current;
        return (
          <div key={s.num} className={`se-step ${active ? 'active' : ''} ${done ? 'done' : ''}`}>
            <motion.div
              className={`se-step-dot ${active ? 'se-step-active' : ''} ${done ? 'se-step-done' : ''}`}
              initial={false}
              animate={active ? { scale: [1, 1.1, 1] } : { scale: 1 }}
              transition={{ duration: 0.4, ease: 'easeInOut' }}
            >
              {done ? '✓' : s.num}
            </motion.div>
            <span className="se-step-label">{t(s.i18nLabel)}</span>
            <span className="se-step-sub">{t(s.i18nSub)}</span>
            {i < STEPS.length - 1 && (
              <div className={`se-step-line ${done ? 'se-step-line-done' : ''}`} />
            )}
          </div>
        );
      })}
    </div>
  );
}

/** Corridor de transfert animé : origine → destination. */
function Corridor({
  origin,
  destination,
}: {
  origin: { flag: string; name: string; code: string } | null;
  destination: { flag: string; name: string; code: string } | null;
}) {
  const active = Boolean(origin && destination);
  return (
    <motion.div
      className="se-corridor"
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35, ease: BEZIER }}
    >
      <span className="se-corridor-state">{active ? '● corridor actif' : '○ en attente'}</span>

      {/* Origine */}
      <div className="se-corridor-end">
        <AnimatePresence mode="wait">
          {origin ? (
            <motion.div key={origin.code} initial={{ opacity: 0, scale: 0.8 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.8 }} transition={{ duration: 0.25 }} style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 4 }}>
              <span className="se-corridor-flag">{origin.flag}</span>
              <span className="se-corridor-name">{origin.name}</span>
              <span className="se-corridor-code">{origin.code}</span>
            </motion.div>
          ) : (
            <motion.span key="empty-origin" initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="se-corridor-empty">Origine</motion.span>
          )}
        </AnimatePresence>
      </div>

      {/* Ligne animée */}
      <div className="se-corridor-path">
        <div className={`se-corridor-line ${active ? '' : 'se-corridor-line-idle'}`} />
        {active && <>
          {[0, 1, 2, 3, 4].map(i => <span key={i} className="se-particle" style={{ animationDelay: `${i * 0.5}s` }} />)}
          <div className="se-corridor-pulse" />
        </>}
      </div>

      {/* Destination */}
      <div className="se-corridor-end">
        <AnimatePresence mode="wait">
          {destination ? (
            <motion.div key={destination.code} initial={{ opacity: 0, scale: 0.8 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.8 }} transition={{ duration: 0.25 }} style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 4 }}>
              <span className="se-corridor-flag">{destination.flag}</span>
              <span className="se-corridor-name">{destination.name}</span>
              <span className="se-corridor-code">{destination.code}</span>
            </motion.div>
          ) : (
            <motion.span key="empty-dest" initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="se-corridor-empty">Destination</motion.span>
          )}
        </AnimatePresence>
      </div>
    </motion.div>
  );
}

/** Panel de télémétrie du moteur (colonne droite). */
function TelemetryPanel({
  residenceCountry,
  kycLevel,
  originsCount,
  coverageCount,
  sourceCurrenciesCount,
  step,
}: {
  residenceCountry: string | null;
  kycLevel: string;
  originsCount: number;
  coverageCount: number;
  sourceCurrenciesCount: number;
  step: Step;
}) {
  const t = useDashT();
  const kycColor = kycLevel === 'verified' ? 'var(--green)' : kycLevel === 'pending' ? 'var(--gold)' : 'var(--red)';
  const kycLabel = kycLevel === 'verified' ? t('send.kyc.verified') : kycLevel === 'pending' ? t('send.kyc.pending') : t('send.kyc.unverified');
  const statusLabel = step === 1 ? 'CONFIGURATION' : step === 2 ? 'VALIDATION' : 'ROUTING ACTIF';

  return (
    <motion.div className="card se-tele" initial={{ opacity: 0, x: 20 }} animate={{ opacity: 1, x: 0 }} transition={{ duration: 0.35, delay: 0.15 }}>
      <div className="se-tele-title">
        <div className="ib ib-c" style={{ width: 28, height: 28, borderRadius: 7, fontSize: 13 }}>⚙</div>
        <span style={{ fontSize: 9, fontFamily: 'var(--font-mono)', letterSpacing: '0.14em', textTransform: 'uppercase', color: 'var(--text-dim)' }}>
          MOTEUR // STATUS
        </span>
        <span className="pill p-c" style={{ fontSize: 7, marginLeft: 'auto' }}>{statusLabel}</span>
      </div>

      {/* KYC */}
      <div className="se-tele-row">
        <span className="se-tele-k">Résidence KYC</span>
        <span className="se-tele-v">{residenceCountry ?? '—'}</span>
      </div>
      <div className="se-tele-row">
        <span className="se-tele-k">Niveau KYC</span>
        <span className="se-tele-v" style={{ color: kycColor }}>
          <span style={{ width: 6, height: 6, borderRadius: '50%', background: kycColor, boxShadow: `0 0 6px ${kycColor}` }} />
          {kycLabel}
        </span>
      </div>

      <div style={{ height: 1, background: 'var(--border-soft)', margin: '8px 0' }} />

      {/* Sources */}
      <div className="se-tele-row">
        <span className="se-tele-k">Origines autorisées</span>
        <span className="se-tele-v" style={{ color: originsCount > 0 ? 'var(--cyan)' : 'var(--red)' }}>
          {originsCount}
        </span>
      </div>
      <div className="se-tele-row">
        <span className="se-tele-k">Devises source</span>
        <span className="se-tele-v">{sourceCurrenciesCount}</span>
      </div>
      <div className="se-tele-row">
        <span className="se-tele-k">Destinations</span>
        <span className="se-tele-v">{coverageCount} pays</span>
      </div>

      <div style={{ height: 1, background: 'var(--border-soft)', margin: '8px 0' }} />

      {/* Engine info */}
      <div className="se-tele-row">
        <span className="se-tele-k">Pipeline</span>
        <span className="se-tele-v" style={{ color: 'var(--violet)' }}>7 moteurs</span>
      </div>
      <div className="se-tele-row">
        <span className="se-tele-k">Latence</span>
        <span className="se-tele-v" style={{ color: 'var(--green)', fontFamily: 'var(--font-mono)' }}>
          {'<'}180ms
        </span>
      </div>
    </motion.div>
  );
}

/** Sélecteur de pays searchable avec animations. */
function CountrySelector({
  countries,
  selectedCode,
  onSelect,
  placeholder,
}: {
  countries: IntentCountry[];
  selectedCode: string;
  onSelect: (code: string) => void;
  placeholder?: string;
}) {
  const t = useDashT();
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const containerRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, []);

  const filtered = useMemo(() => {
    if (!query.trim()) return countries;
    const q = query.toLowerCase().trim();
    return countries.filter(c => c.name.toLowerCase().includes(q) || c.code.toLowerCase().includes(q));
  }, [countries, query]);

  const selected = countries.find(c => c.code === selectedCode);

  return (
    <div ref={containerRef} style={{ position: 'relative' }}>
      <div
        className="se-input"
        onClick={() => { setOpen(true); setTimeout(() => inputRef.current?.focus(), 50); }}
        style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer', padding: '10px 12px' }}
      >
        {selected ? (
          <>
            <span style={{ fontSize: 16 }}>{selected.flag}</span>
            <span style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-bright)', flex: 1 }}>{selected.name}</span>
            <span className="mono" style={{ fontSize: 9, color: 'var(--text-dim)' }}>{selected.code}</span>
          </>
        ) : (
          <span style={{ fontSize: 12, color: 'var(--text-dim)', flex: 1 }}>            {placeholder || t('send.selectCountry')}</span>
        )}
        <span style={{ fontSize: 9, color: 'var(--text-dim)', transform: open ? 'rotate(180deg)' : 'none', transition: 'transform 0.15s' }}>▼</span>
      </div>

      {open && (
        <motion.div
          className="card"
          initial={{ opacity: 0, y: 8, scale: 0.97 }}
          animate={{ opacity: 1, y: 0, scale: 1 }}
          transition={{ duration: 0.2, ease: BEZIER }}
          style={{ position: 'absolute', bottom: '100%', left: 0, right: 0, zIndex: 100, marginBottom: 6, padding: 8, maxHeight: 280, overflowY: 'auto' }}
          onClick={e => e.stopPropagation()}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '6px 8px', background: 'var(--panel2)', border: '1px solid var(--border)', borderRadius: 6, marginBottom: 8 }}>
            <span style={{ fontSize: 12, color: 'var(--text-dim)' }}>🔍</span>
            <input ref={inputRef} type="text" value={query} onChange={e => setQuery(e.target.value)} placeholder={t('send.searchCountry')} style={{ flex: 1, background: 'transparent', border: 'none', outline: 'none', color: 'var(--text-bright)', fontSize: 11 }} />
            {query && <button onClick={() => setQuery('')} style={{ background: 'none', border: 'none', color: 'var(--text-dim)', cursor: 'pointer', fontSize: 10 }}>✕</button>}
          </div>
          {filtered.length === 0 ? (
            <div style={{ padding: '16px 8px', textAlign: 'center', fontSize: 11, color: 'var(--text-dim)' }}>
              Aucun pays trouvé pour « {query} »
            </div>
          ) : (
            filtered.map(c => (
              <div
                key={c.code}
                onClick={() => { onSelect(c.code); setOpen(false); setQuery(''); }}
                style={{
                  display: 'flex', alignItems: 'center', gap: 8, padding: '7px 8px', borderRadius: 6, cursor: 'pointer', transition: 'background 0.12s',
                  background: selectedCode === c.code ? 'rgba(0,200,255,0.06)' : 'transparent',
                }}
                onMouseEnter={e => { if (selectedCode !== c.code) e.currentTarget.style.background = 'rgba(255,255,255,0.03)'; }}
                onMouseLeave={e => { if (selectedCode !== c.code) e.currentTarget.style.background = 'transparent'; }}
              >
                <span style={{ fontSize: 16, width: 24, textAlign: 'center' }}>{c.flag}</span>
                <span style={{ fontSize: 12, fontWeight: 500, color: 'var(--text-bright)', flex: 1 }}>{c.name}</span>
                <span className="mono" style={{ fontSize: 9, color: 'var(--text-dim)' }}>{c.code}</span>
              </div>
            ))
          )}
          <div style={{ fontSize: 9, color: 'var(--text-dim)', textAlign: 'center', marginTop: 8, paddingTop: 6, borderTop: '1px solid var(--border-soft)' }}>
            {filtered.length} pays disponible{filtered.length > 1 ? 's' : ''}
          </div>
        </motion.div>
      )}
    </div>
  );
}

// ─── Composant principal ─────────────────────────────────────────────────────

export default function SendPage() {
  const t = useDashT();
  const { lang } = useI18n();
  const locale = localeFor(lang);
  const [step, setStep] = useState<Step>(1);
  const [completedSteps, setCompletedSteps] = useState<Step[]>([]);

  const [amount, setAmount] = useState('');
  const [fundingType, setFundingType] = useState<'wallet' | 'provider'>('wallet');
  const [sourceCurrency, setSourceCurrency] = useState('');
  const [originCountry, setOriginCountry] = useState('');
  const [destCountry, setDestCountry] = useState('');
  const [destCurrency, setDestCurrency] = useState('');
  const [receivingMethod, setReceivingMethod] = useState('');
  const [beneficiary, setBeneficiary] = useState<BeneficiaryInfo>({ name: '', reference: '', secondaryRef: '', operator: '' });
  const [objective, setObjective] = useState('optimized');

  const [coverage, setCoverage] = useState<IntentCoverageData | null>(null);
  const [authorizedOrigins, setAuthorizedOrigins] = useState<AuthorizedOriginsData | null>(null);
  const [wallets, setWallets] = useState<WalletState[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // ─── Chargement ─────────────────────────────────────────────────────

  const fetchAll = useCallback(async () => {
    setLoading(true);
    setError(null);
    const [covRes, originsRes, walletsRes] = await Promise.all([apiIntentCoverage(), apiAuthorizedOrigins(), apiWalletsList()]);
    if (!covRes.success || !covRes.data) {
      setError(covRes.error || 'Erreur lors du chargement de la couverture provider.');
      setLoading(false);
      return;
    }
    setCoverage(covRes.data);
    // Wallets réels de l'utilisateur → source des fonds potentielle.
    if (walletsRes.success && walletsRes.data) {
      setWallets(walletsRes.data.wallets);
    }
    if (originsRes.success && originsRes.data) {
      setAuthorizedOrigins(originsRes.data);
      if (originsRes.data.origins.length === 1) {
        setOriginCountry(originsRes.data.origins[0].country);
      }
    }
    setLoading(false);
  }, []);

  useEffect(() => { fetchAll(); }, [fetchAll]);

  // ─── Données dérivées ────────────────────────────────────────────────

  // Devises de la source des fonds : les wallets RÉELS de l'utilisateur
  // (devises avec un solde disponible) priment ; sinon repli sur la couverture.
  const sourceCurrencies = useMemo(() => {
    const walletCurs = wallets.filter(w => w.has_funds || w.available > 0).map(w => w.currency);
    return walletCurs.length > 0 ? walletCurs : (coverage?.source_currencies ?? []);
  }, [wallets, coverage]);
  const selectedCountry = useMemo(() => coverage?.countries.find(c => c.code === destCountry) ?? null, [coverage, destCountry]);
  const destCurrencies = useMemo(() => selectedCountry?.currencies ?? [], [selectedCountry]);
  const selectedDestCurrency = useMemo(() => destCurrencies.find(c => c.code === destCurrency) ?? null, [destCurrencies, destCurrency]);
  const availableMethods = useMemo(() => selectedDestCurrency?.methods ?? [], [selectedDestCurrency]);
  const selectedMethod = useMemo(() => availableMethods.find(m => m.type === receivingMethod) ?? null, [availableMethods, receivingMethod]);
  const mobileOperators = useMemo(() => selectedMethod?.type === 'mobile_money' ? (selectedMethod.operators ?? []) : [], [selectedMethod]);

  const estimatedReceived = useMemo(() => {
    if (!coverage || !amount || !sourceCurrency || !destCurrency) return 0;
    const num = parseFloat(amount);
    if (isNaN(num) || num <= 0) return 0;
    return estimateConvert(num, sourceCurrency, destCurrency, coverage.rates);
  }, [coverage, amount, sourceCurrency, destCurrency]);

  const estimatedFees = useMemo(() => {
    if (!coverage || !amount || !sourceCurrency) return 0;
    const num = parseFloat(amount);
    if (isNaN(num) || num <= 0) return 0;
    return num * (coverage.fee_estimate_pct / 100);
  }, [coverage, amount, sourceCurrency]);

  const selectedOrigin = useMemo(() => authorizedOrigins?.origins.find(o => o.country === originCountry) ?? null, [authorizedOrigins, originCountry]);

  // ─── Validation ──────────────────────────────────────────────────────

  const validateStep = useCallback((): FormErrors => {
    const errors: FormErrors = {};
    if (step === 1) {
      const num = parseFloat(amount);
      if (!amount || isNaN(num) || num <= 0) errors.amount = t('send.validation.amount');
      if (fundingType === 'wallet') {
        if (!sourceCurrency) errors.sourceCurrency = t('send.validation.walletCurrency');
      } else {
        if (!originCountry) errors.originCountry = t('send.validation.provider');
      }
      if (!destCountry) errors.destCountry = t('send.validation.destCountry');
      if (destCountry && !destCurrency) errors.destCurrency = t('send.validation.destCurrency');
      if (destCountry && destCurrency && !receivingMethod) errors.receivingMethod = t('send.validation.receivingMethod');
      if (receivingMethod) {
        const fields = beneficiaryFieldsForMethod(receivingMethod, t);
        if (fields.nameLabel && !beneficiary.name.trim()) errors.beneficiaryName = t('send.validation.fieldRequired', { field: fields.nameLabel });
        if (fields.refLabel && !beneficiary.reference.trim()) errors.beneficiaryRef = t('send.validation.fieldRequired', { field: fields.refLabel });
      }
    }
    return errors;
  }, [step, amount, fundingType, sourceCurrency, originCountry, destCountry, destCurrency, receivingMethod, beneficiary]);

  const [errors, setErrors] = useState<FormErrors>({});

  // ─── Navigation ──────────────────────────────────────────────────────

  const goNext = () => {
    if (step === 1) {
      const errs = validateStep(); setErrors(errs);
      if (Object.keys(errs).length > 0) return;
      setCompletedSteps([1]); setStep(2);
    }
  };
  const goBack = () => {
    if (step === 3) { setStep(2); setCompletedSteps([1]); }
    else if (step === 2) { setStep(1); setCompletedSteps([]); }
  };
  const goToRouting = () => { setCompletedSteps([1, 2]); setStep(3); };

  // ─── Réinitialisations ──────────────────────────────────────────────

  useEffect(() => { setDestCurrency(''); setReceivingMethod(''); setBeneficiary({ name: '', reference: '', secondaryRef: '', operator: '' }); }, [destCountry]);
  useEffect(() => { setReceivingMethod(''); setBeneficiary({ name: '', reference: '', secondaryRef: '', operator: '' }); }, [destCurrency]);
  useEffect(() => { setBeneficiary({ name: '', reference: '', secondaryRef: '', operator: '' }); }, [receivingMethod]);

  // ─── États de chargement / erreur ────────────────────────────────────

  if (loading || !coverage) {
    return (
      <div className="page">
        <div className="page-header animate-up">
          <div className="page-label">NEXUS TRANSFER ENGINE</div>
          <div className="page-title">Initialisation <span className="gc">du moteur…</span></div>
        </div>
        <div className="se-boot">
          <div className="se-boot-ring"><div className="se-boot-core" /></div>
          <div className="se-boot-log">Recherche des options disponibles…</div>
        </div>
        <div className="shimmer-bg" style={{ height: 80, borderRadius: 16, marginTop: 20 }} />
        <div className="shimmer-bg" style={{ height: 400, borderRadius: 16, marginTop: 16 }} />
      </div>
    );
  }

  if (error) {
    return (
      <div className="page" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100%' }}>
        <div className="card card-hi-c" style={{ padding: 40, textAlign: 'center', maxWidth: 460 }}>
          <div style={{ fontSize: 28, marginBottom: 12 }}>⚠️</div>
          <h2 style={{ color: 'var(--text-bright)', marginBottom: 10, fontSize: 18 }}>{t('send.engineOffline')}</h2>
          <p style={{ color: 'var(--text-mid)', marginBottom: 20 }}>{error}</p>
          <button className="btn btn-cyan" onClick={fetchAll}>{t('send.restart')}</button>
        </div>
      </div>
    );
  }

  // ─── Origine info pour corridor ──────────────────────────────────────

  const corridorOrigin = selectedOrigin
    ? { flag: selectedOrigin.flag, name: selectedOrigin.countryName, code: selectedOrigin.country }
    : null;
  const corridorDest = selectedCountry
    ? { flag: selectedCountry.flag, name: selectedCountry.name, code: selectedCountry.code }
    : null;

  // ─── Rendu ────────────────────────────────────────────────────────────

  return (
    <div className="page">
      {/* En-tête moteur */}
      <div className="page-header animate-up">
        <div className="se-header">
          <div>
            <div className="page-label">NEXUS TRANSFER ENGINE</div>
            <div className="page-title">{t('send.intro1')} <span className="gc">{t('send.intro2')}</span></div>
          </div>
          <div className="se-badge"><span className="se-led" /> ENGINE // LIVE</div>
        </div>
      </div>

      {/* Rail d'étapes */}
      <div className="animate-up delay-1">
        <StepRail current={step} completed={completedSteps} />
      </div>

      {/* Corridor (étapes 1-2) */}
      {step <= 2 && (
        <div className="animate-up delay-2">
          <Corridor origin={corridorOrigin} destination={corridorDest} />
        </div>
      )}

      {/* ═══ Étapes 1-2 : formulaire animé ═══════════════════════════════ */}
      <AnimatePresence mode="wait">
        {step <= 2 && (
          <motion.div key={`form-s${step}`} className="se-layout" {...fadeSlideUp}>

            {/* ── Colonne principale ──────────────────────────────── */}
            <div className="se-main">

              {/* ── ÉTAPE 1 : ÉLIGIBILITÉ ────────────────────────── */}
              {step === 1 && (
                <div className="card card-hi-c" style={{ padding: 24 }}>
                  <div className="page-label" style={{ marginBottom: 16 }}>{t('send.details')}</div>

                  <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>

                    {/* Source des fonds : wallet OU proposition du provider */}
                    <motion.div {...stagger(0)}>
                      <div className="se-field-label">{t('send.source')}</div>

                      {/* Toggle Wallet / Provider */}
                      <div style={{ display: 'flex', gap: 6, marginBottom: 12 }}>
                        <button
                          onClick={() => { setFundingType('wallet'); setOriginCountry(''); }}
                          className={`se-chip ${fundingType === 'wallet' ? 'se-chip-selected' : ''}`}
                          style={{ flex: 1, padding: '9px 12px' }}
                        >
                          💼 Mon wallet
                        </button>
                        <button
                          onClick={() => { setFundingType('provider'); setSourceCurrency(''); }}
                          className={`se-chip ${fundingType === 'provider' ? 'se-chip-selected' : ''}`}
                          style={{ flex: 1, padding: '9px 12px' }}
                        >
                          🏦 Proposition du provider
                        </button>
                      </div>

                      {fundingType === 'wallet' ? (
                        <>
                          <div className="se-amount">
                            <select
                              value={sourceCurrency || (sourceCurrencies[0] ?? '')}
                              onChange={e => setSourceCurrency(e.target.value)}
                              style={{ padding: '11px 10px', background: 'var(--panel2)', color: 'var(--cyan)', fontWeight: 700, fontSize: 12, outline: 'none', border: 'none', borderRight: '1px solid var(--border)', cursor: 'pointer' }}
                            >
                              {sourceCurrencies.map(c => {
                                const w = wallets.find(x => x.currency === c);
                                return <option key={c} value={c}>{w && (w.available > 0 || w.has_funds) ? `${c} · ${formatCurrencyDisplay(w.available, c, locale)} ${t('send.beneficiary.available')}` : c}</option>;
                              })}
                            </select>
                            <input type="text" value={amount} onChange={e => setAmount(e.target.value.replace(/[^0-9.]/g, ''))} placeholder="0.00" />
                          </div>
                          {wallets.length > 0 && (
                            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 8 }}>
                              {wallets.filter(w => w.available > 0 || w.has_funds).map(w => (
                                <button
                                  key={w.currency}
                                  onClick={() => setSourceCurrency(w.currency)}
                                  className={`se-chip ${sourceCurrency === w.currency ? 'se-chip-selected' : ''}`}
                                  style={{ fontSize: 10, padding: '5px 10px' }}
                                >
                                  {w.currency} · {formatCurrencyDisplay(w.available, w.currency, locale)}
                                </button>
                              ))}
                            </div>
                          )}
                          {errors.sourceCurrency && <ErrorText>{errors.sourceCurrency}</ErrorText>}
                        </>
                      ) : (
                        <>
                          {authorizedOrigins?.country_of_residence && (
                            <div className="se-residence" style={{ marginBottom: 8 }}>
                              <span style={{ fontSize: 9, color: 'var(--text-dim)' }}>Résidence KYC :</span>
                              <strong>{authorizedOrigins.country_of_residence}</strong>
                              <span className="se-lock">🔒 non modifiable</span>
                            </div>
                          )}

                          {authorizedOrigins && authorizedOrigins.origins.length === 0 ? (
                            <div style={{
                              padding: '12px 14px', background: 'rgba(255,100,100,0.04)',
                              border: '1px solid rgba(255,100,100,0.2)', borderRadius: 10,
                              fontSize: 11, color: 'var(--red)',
                            }}>
                              Aucune source de financement vérifiée. Ajoutez et vérifiez un moyen de paiement pour envoyer des fonds.
                            </div>
                          ) : authorizedOrigins && authorizedOrigins.origins.length === 1 ? (
                            <div className="se-chip se-chip-locked">
                              <span style={{ fontSize: 16 }}>{authorizedOrigins.origins[0].flag}</span>
                              <span>{authorizedOrigins.origins[0].countryName}</span>
                              <span className="se-chip-sub">{authorizedOrigins.origins[0].sources[0]?.kindLabel}</span>
                            </div>
                          ) : (
                            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                              {authorizedOrigins?.origins.map((origin, i) => (
                                <motion.button
                                  key={origin.country}
                                  className={`se-chip ${originCountry === origin.country ? 'se-chip-selected' : ''}`}
                                  onClick={() => { setOriginCountry(origin.country); if (origin.currency) setSourceCurrency(origin.currency); }}
                                  {...stagger(i, 0.05)}
                                >
                                  <span style={{ fontSize: 14 }}>{origin.flag}</span>
                                  <span>{origin.countryName}</span>
                                  {origin.sources.length > 0 && (
                                    <span className="se-chip-sub">{origin.sources[0].kindLabel}</span>
                                  )}
                                  {originCountry === origin.country && (
                                    <span className="pill p-c" style={{ fontSize: 7, marginLeft: 'auto' }}>✓</span>
                                  )}
                                </motion.button>
                              ))}
                            </div>
                          )}
                          {errors.originCountry && <ErrorText>{errors.originCountry}</ErrorText>}
                        </>
                      )}
                      {errors.amount && <ErrorText>{errors.amount}</ErrorText>}
                    </motion.div>

                    {/* Estimation conversion */}
                    {amount && sourceCurrency && destCurrency && estimatedReceived !== null && estimatedReceived > 0 && (
                      <motion.div className="se-estimate" initial={{ opacity: 0, scale: 0.97 }} animate={{ opacity: 1, scale: 1 }} transition={{ duration: 0.25 }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', paddingLeft: 10 }}>
                          <div style={{ fontSize: 10, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>
                            {formatCurrencyDisplay(parseFloat(amount) || 0, sourceCurrency, locale)}
                          </div>
                          <div style={{ fontSize: 14, color: 'var(--cyan)', fontWeight: 600 }}>≈</div>
                          <div>
                            <span style={{ fontSize: 17, fontWeight: 800, color: 'var(--white)', fontFamily: 'var(--font-mono)' }}>
                              <AnimatedValue value={estimatedReceived} format={v => formatCurrencyDisplay(v, destCurrency, locale)} />
                            </span>
                          </div>
                        </div>
                        <div style={{ fontSize: 9, color: 'var(--text-dim)', marginTop: 6, textAlign: 'center', fontStyle: 'italic', paddingLeft: 10 }}>
                          {t('send.estimateNote')}
                        </div>
                      </motion.div>
                    )}

                    {/* Destination */}
                    <motion.div {...stagger(2)}>
                      <div className="se-field-label">{t('send.destCountry')}</div>
                      <CountrySelector countries={coverage.countries} selectedCode={destCountry} onSelect={setDestCountry} placeholder={t('send.selectCountry')} />
                      {errors.destCountry && <ErrorText>{errors.destCountry}</ErrorText>}
                    </motion.div>

                    {/* Devise réception */}
                    {destCountry && destCurrencies.length > 0 && (
                      <motion.div {...stagger(3)}>
                        <div className="se-field-label">{t('send.destCurrency')}</div>
                        {destCurrencies.length === 1 ? (
                          <div className="se-chip se-chip-locked">
                            <span className="pill p-c" style={{ fontSize: 8 }}>{destCurrencies[0].code}</span>
                            <span>{destCurrencies[0].name}</span>
                            <span style={{ fontSize: 9, color: 'var(--text-dim)', marginLeft: 'auto' }}>{destCurrencies[0].symbol}</span>
                          </div>
                        ) : (
                          <select className="se-input" value={destCurrency} onChange={e => setDestCurrency(e.target.value)} style={{ cursor: 'pointer' }}>
                            <option value="">— Choisir —</option>
                            {destCurrencies.map(c => <option key={c.code} value={c.code}>{c.code} — {c.name}</option>)}
                          </select>
                        )}
                        {errors.destCurrency && <ErrorText>{errors.destCurrency}</ErrorText>}
                      </motion.div>
                    )}

                    {/* Mode de réception */}
                    {destCurrency && availableMethods.length > 0 && (
                      <motion.div {...stagger(4)}>
                        <div className="se-field-label">{t('send.method')}</div>
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 8 }}>
                          {availableMethods.map(m => (
                            <motion.button
                              key={m.type}
                              className={`se-method ${receivingMethod === m.type ? 'se-method-selected' : ''}`}
                              onClick={() => setReceivingMethod(m.type)}
                              whileHover={{ y: -2 }}
                              whileTap={{ scale: 0.97 }}
                            >
                              <span className="se-method-icon">{m.icon}</span>
                              <span style={{ fontSize: 10, fontWeight: 600, color: receivingMethod === m.type ? 'var(--cyan)' : 'var(--text-bright)' }}>{m.label}</span>
                            </motion.button>
                          ))}
                        </div>
                        {errors.receivingMethod && <ErrorText>{errors.receivingMethod}</ErrorText>}
                      </motion.div>
                    )}

                    {/* Opérateur Mobile Money */}
                    {selectedMethod?.type === 'mobile_money' && mobileOperators.length > 0 && (
                      <motion.div {...stagger(5)}>                          <div className="se-field-label">{t('send.operator')}</div>
                        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                          {mobileOperators.map(op => (
                            <button
                              key={op}
                              onClick={() => setBeneficiary(b => ({ ...b, operator: op }))}
                              className={`se-chip ${beneficiary.operator === op ? 'se-chip-selected' : ''}`}
                            >
                              {beneficiary.operator === op ? '✓ ' : ''}{op}
                            </button>
                          ))}
                        </div>
                      </motion.div>
                    )}

                    {/* Champs bénéficiaire */}
                    {receivingMethod && (() => {
                      const fields = beneficiaryFieldsForMethod(receivingMethod, t);
                      return (
                        <motion.div {...stagger(6)} style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                          <div className="se-field-label">{t('send.beneficiary')}</div>
                          <div>
                            <div className="se-field-label" style={{ marginBottom: 4 }}>{fields.nameLabel}</div>
                            <input className="se-input" value={beneficiary.name} onChange={e => setBeneficiary(b => ({ ...b, name: e.target.value }))} placeholder={t('send.fullNamePh')} />
                            {errors.beneficiaryName && <ErrorText>{errors.beneficiaryName}</ErrorText>}
                          </div>
                          <div>
                            <div className="se-field-label" style={{ marginBottom: 4 }}>{fields.refLabel}</div>
                            <input className="se-input" value={beneficiary.reference} onChange={e => setBeneficiary(b => ({ ...b, reference: e.target.value }))} placeholder={fields.refPlaceholder} style={{ fontFamily: 'var(--font-mono)' }} />
                            {errors.beneficiaryRef && <ErrorText>{errors.beneficiaryRef}</ErrorText>}
                          </div>
                          {fields.secondaryLabel && receivingMethod === 'crypto' && (
                            <div>
                              <div className="se-field-label" style={{ marginBottom: 4 }}>{fields.secondaryLabel}</div>
                              <select className="se-input" value={beneficiary.secondaryRef} onChange={e => setBeneficiary(b => ({ ...b, secondaryRef: e.target.value }))}>
                                <option value="">— Réseau —</option>
                                {coverage.crypto_networks.map(n => <option key={n} value={n}>{n}</option>)}
                              </select>
                            </div>
                          )}
                          {fields.secondaryLabel && receivingMethod === 'cash_pickup' && (
                            <div>
                              <div className="se-field-label" style={{ marginBottom: 4 }}>{fields.secondaryLabel}</div>
                              <input className="se-input" value={beneficiary.secondaryRef} onChange={e => setBeneficiary(b => ({ ...b, secondaryRef: e.target.value }))} placeholder={fields.secondaryPlaceholder} />
                            </div>
                          )}
                        </motion.div>
                      );
                    })()}

                    {/* Mode de transfert (préférence de routage) */}
                    {receivingMethod && (
                      <motion.div {...stagger(7)}>
                        <div className="se-field-label">{t('send.how')}</div>
                        <div style={{ fontSize: 11, color: 'var(--text-mid)', marginBottom: 8 }}>
                          {t('send.ai1')}
                        </div>
                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
                          {OBJECTIVE_OPTIONS.map((opt, oi) => (
                            <motion.button
                              key={opt.key}
                              onClick={() => setObjective(opt.key)}
                              className={`se-chip ${objective === opt.key ? 'se-chip-selected' : ''}`}
                              style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-start', gap: 2, textAlign: 'left', padding: '9px 11px', position: 'relative', overflow: 'hidden' }}
                              initial={{ opacity: 0, y: 14, scale: 0.97 }}
                              animate={{ opacity: 1, y: 0, scale: 1 }}
                              transition={{ duration: 0.35, delay: oi * 0.05, ease: EASE }}
                              whileHover={{ y: -3, scale: 1.02 }}
                              whileTap={{ scale: 0.96 }}
                            >
                              <span style={{ fontSize: 12, fontWeight: 600, display: 'flex', alignItems: 'center', gap: 6 }}>
                                <motion.span
                                  animate={{ rotate: objective === opt.key ? [0, -8, 8, 0] : 0 }}
                                  transition={{ duration: 0.4 }}
                                >{opt.icon}</motion.span>
                                {t(opt.i18nLabel)}
                              </span>
                              <span style={{ fontSize: 9.5, color: 'var(--text-mid)', fontWeight: 400, lineHeight: 1.3 }}>{t(opt.i18nDesc)}</span>
                            </motion.button>
                          ))}
                        </div>
                      </motion.div>
                    )}

                    {/* Navigation */}
                    <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
                      <button className="se-cta" onClick={goNext} style={{ flex: 1 }}>{t('send.next')} →</button>
                    </div>
                  </div>
                </div>
              )}

              {/* ── ÉTAPE 2 : CONFIRMATION ────────────────────────── */}
              {step === 2 && (
                <div className="card card-hi-c" style={{ padding: 24 }}>
                  <div className="page-label" style={{ marginBottom: 16 }}>{t('send.step2')} — {t('send.step2.sub')}</div>

                  <div style={{ display: 'flex', flexDirection: 'column', gap: 0 }}>
                    {[
                      [t('send.youSend'), formatCurrencyDisplay(parseFloat(amount) || 0, sourceCurrency, locale), 'var(--white)', true],
                      [t('send.source'), fundingType === 'wallet'
                        ? t('send.walletSrc', { currency: sourceCurrency })
                        : t('send.originSrc', { flag: selectedOrigin?.flag ?? '', name: selectedOrigin?.countryName ?? originCountry }), 'var(--green)', false],
                      [t('send.destination'), `${selectedCountry?.flag ?? ''} ${selectedCountry?.name ?? destCountry}`, 'var(--text-bright)', false],
                      [t('send.receiveCurrency'), destCurrency, 'var(--cyan)', true],
                      [t('send.method'), `${selectedMethod?.icon ?? ''} ${selectedMethod?.label ?? ''}${beneficiary.operator ? ` — ${beneficiary.operator}` : ''}`, 'var(--text-bright)', false],
                    ].map(([k, v, col, mono], i) => (
                      <motion.div key={k as string} className="trow" {...stagger(i)}>
                        <span style={{ fontSize: 11, color: 'var(--text-mid)' }}>{k as string}</span>
                        <span style={{ fontSize: mono ? 15 : 12, fontWeight: 700, color: col as string, fontFamily: mono ? 'var(--font-mono)' : 'inherit' }}>{v as string}</span>
                      </motion.div>
                    ))}

                    {/* Bénéficiaire */}
                    <motion.div className="trow" {...stagger(5)}>
                      <span style={{ fontSize: 11, color: 'var(--text-mid)' }}>{t('send.beneficiary')}</span>
                      <div style={{ textAlign: 'right' }}>
                        <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-bright)' }}>{beneficiary.name || '—'}</div>
                        <div style={{ fontSize: 9, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>
                          {beneficiary.reference ? `${beneficiary.reference.substring(0, 12)}…` : '—'}
                        </div>
                      </div>
                    </motion.div>

                    {/* Objectif */}
                    <motion.div className="trow" {...stagger(6)}>
                      <span style={{ fontSize: 11, color: 'var(--text-mid)' }}>{t('send.modeTransfer')}</span>
                      <span style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-bright)' }}>
                        {OBJECTIVE_OPTIONS.find(o => o.key === objective)?.icon} {t(OBJECTIVE_OPTIONS.find(o => o.key === objective)?.i18nLabel ?? 'send.obj.optimized')}
                      </span>
                    </motion.div>

                    <div className="div" style={{ margin: '8px 0' }} />

                    {/* Frais */}
                    <motion.div className="trow" {...stagger(7)}>
                      <span style={{ fontSize: 11, color: 'var(--text-mid)' }}>{t('send.estFees')}</span>
                      <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--gold)', fontFamily: 'var(--font-mono)' }}>
                        {formatCurrencyDisplay(estimatedFees, sourceCurrency, locale)}
                      </span>
                    </motion.div>

                    {/* Montant reçu estimé */}
                    <motion.div className="trow" {...stagger(8)}>
                      <span style={{ fontSize: 11, color: 'var(--text-mid)' }}>{t('send.estReceived')}</span>
                      <span style={{ fontSize: 18, fontWeight: 800, color: 'var(--green)', fontFamily: 'var(--font-mono)' }}>
                        {estimatedReceived !== null
                          ? formatCurrencyDisplay(estimatedReceived, destCurrency, locale)
                          : <span style={{ fontSize: 10, color: 'var(--text-dim)' }}>{t('send.estUnavailable')}</span>}
                      </span>
                    </motion.div>
                  </div>

                  <div style={{ fontSize: 9, color: 'var(--text-dim)', textAlign: 'center', marginTop: 14, padding: '8px 12px', background: 'var(--panel2)', borderRadius: 8, fontStyle: 'italic' }}>
                    {t('send.summaryNote')}
                  </div>

                  <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
                    <button className="se-cta se-cta-ghost" onClick={goBack}>{t('send.edit')}</button>
                    <button className="se-cta" onClick={goToRouting} style={{ flex: 1 }}>
                      {t('send.engageRouting')}
                    </button>
                  </div>
                </div>
              )}
            </div>

            {/* ── Colonne droite : Télémétrie ──────────────────────── */}
            <aside className="se-rail">
              <TelemetryPanel
                residenceCountry={authorizedOrigins?.country_of_residence ?? null}
                kycLevel={authorizedOrigins?.kyc_level ?? 'none'}
                originsCount={authorizedOrigins?.origins.length ?? 0}
                coverageCount={coverage.countries.length}
                sourceCurrenciesCount={sourceCurrencies.length}
                step={step}
              />

              {/* Aide contextuelle */}
              <motion.div className="card card-hi-v" initial={{ opacity: 0, x: 20 }} animate={{ opacity: 1, x: 0 }} transition={{ duration: 0.35, delay: 0.25 }}>
                <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 10 }}>
                  <div className="ib ib-v" style={{ width: 26, height: 26, borderRadius: 6, fontSize: 12 }}>🤖</div>
                  <span style={{ fontSize: 9, color: 'var(--violet)', fontFamily: 'var(--font-mono)', letterSpacing: '0.12em' }}>NEXUS AI</span>
                </div>
                <div className="quote quote-v" style={{ padding: '10px 14px' }}>
                  <div className="quote-text" style={{ fontSize: 11, fontStyle: 'normal', fontWeight: 500 }}>
                    {step === 1 && <>{t('send.ai1')}</>}
                    {step === 2 && <>{t('send.ai2')}</>}
                  </div>
                </div>
              </motion.div>
            </aside>
          </motion.div>
        )}
      </AnimatePresence>

      {/* ═══ ÉTAPE 3 : ROUTES — Routing Engine ═════════════════════════ */}
      {step === 3 && (
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4, ease: BEZIER }}>
          <RouteSelectionStep
            intent={{
              action: 'send',
              amount: parseFloat(amount) || 0,
              sourceCurrency: sourceCurrency || 'EUR',
              originCountry: originCountry || undefined,
              destinationCountry: destCountry,
              destinationCurrency: destCurrency,
              receivingMethod: receivingMethod,
              objective,
              beneficiaryName: beneficiary.name,
              beneficiaryReference: beneficiary.reference,
              beneficiaryOperator: beneficiary.operator || beneficiary.secondaryRef,
            }}
            onBack={() => { setStep(2); setCompletedSteps([1]); }}
          />
        </motion.div>
      )}
    </div>
  );
}

// ─── Composants utilitaires ──────────────────────────────────────────────────

function ErrorText({ children }: { children: React.ReactNode }) {
  return (
    <div style={{ fontSize: 10, color: 'var(--red)', marginTop: 4, paddingLeft: 2 }}>
      {children}
    </div>
  );
}
