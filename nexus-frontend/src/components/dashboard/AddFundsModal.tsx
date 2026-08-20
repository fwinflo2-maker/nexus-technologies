import { useState, useEffect, useCallback, useMemo } from 'react';
import {
  apiFundingProposals,
  apiFundingCollect,
  type FundingProposal,
} from '../../api/client';
import { CurrencyLogo } from './CurrencyLogo';
import { useDashT } from '../../data/dashboard-i18n';
import TechLoader from '../anim/TechLoader';
import { useAuth } from '../../context/AuthContext';
import { depositCurrenciesForCountry, paymentModesForCountry } from '../../data/payment-modes';

type Step = 'amount' | 'providers' | 'confirm';

export interface AddFundsModalProps {
  open: boolean;
  onClose: () => void;
  onSuccess?: (currency: string) => void;
  /** Préremplissage (ex. depuis Envoyer). */
  initialCurrency?: string;
  initialAmount?: string;
  /** Ouvre directement sur la liste providers. */
  startAtProviders?: boolean;
}

function methodIcon(method: string): string {
  if (method === 'mobile_money') return '📱';
  if (method === 'bank') return '🏦';
  if (method === 'card') return '💳';
  return '🔗';
}

function etaLabel(minutes: number, t: (k: string, p?: Record<string, string | number>) => string): string {
  if (minutes < 60) return t('wallet.fund.etaMin', { n: minutes });
  if (minutes < 1440) return t('wallet.fund.etaHours', { n: Math.round(minutes / 60) });
  return t('wallet.fund.etaDays', { n: Math.round(minutes / 1440) });
}

function pickAllowedCurrency(preferred: string | undefined, allowed: string[], fallback: string): string {
  if (preferred && allowed.includes(preferred)) return preferred;
  if (allowed.includes(fallback)) return fallback;
  return allowed[0] ?? 'EUR';
}

/**
 * Modal Ajouter des fonds — 3 étapes :
 * montant/devise → propositions providers (pays compte) → confirmation.
 * Les devises affichées dépendent du pays d’enregistrement (ex. FR → pas de XAF/XOF).
 */
export default function AddFundsModal({
  open,
  onClose,
  onSuccess,
  initialCurrency,
  initialAmount = '100',
  startAtProviders = false,
}: AddFundsModalProps) {
  const t = useDashT();
  const { user } = useAuth();
  const regCountry = (user?.country_of_residence ?? '').toUpperCase();
  const allowedCurrencies = useMemo(
    () => depositCurrenciesForCountry(regCountry),
    [regCountry],
  );
  const countryDefaultCur = paymentModesForCountry(regCountry)?.default_currency
    ?? allowedCurrencies[0]
    ?? 'EUR';

  const [step, setStep] = useState<Step>(startAtProviders ? 'providers' : 'amount');
  const [currency, setCurrency] = useState(() =>
    pickAllowedCurrency(initialCurrency, allowedCurrencies, countryDefaultCur)
  );
  const [amount, setAmount] = useState(initialAmount);
  const [proposals, setProposals] = useState<FundingProposal[]>([]);
  const [country, setCountry] = useState<string | null>(regCountry || null);
  const [listMessage, setListMessage] = useState<string | null>(null);
  const [selected, setSelected] = useState<FundingProposal | null>(null);
  const [reference, setReference] = useState('');
  const [loadingList, setLoadingList] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [ok, setOk] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    const allowed = depositCurrenciesForCountry(regCountry);
    const fallback = paymentModesForCountry(regCountry)?.default_currency ?? allowed[0] ?? 'EUR';
    setCurrency(pickAllowedCurrency(initialCurrency, allowed, fallback));
    setAmount(initialAmount);
    setStep(startAtProviders ? 'providers' : 'amount');
    setSelected(null);
    setReference('');
    setError(null);
    setOk(null);
    setCountry(regCountry || null);
  }, [open, initialCurrency, initialAmount, startAtProviders, regCountry]);

  useEffect(() => {
    if (!allowedCurrencies.includes(currency)) {
      setCurrency(countryDefaultCur);
    }
  }, [allowedCurrencies, currency, countryDefaultCur]);

  const loadProposals = useCallback(async (cur: string) => {
    setLoadingList(true);
    setError(null);
    const res = await apiFundingProposals(cur);
    setLoadingList(false);
    if (!res.success || !res.data) {
      setError(res.error || t('wallet.fund.error'));
      setProposals([]);
      return;
    }
    setCountry(res.data.country);
    setListMessage(res.data.message);
    setProposals(res.data.proposals);
  }, [t]);

  useEffect(() => {
    if (!open) return;
    if (step === 'providers' || step === 'confirm') {
      loadProposals(currency);
    }
  }, [open, step, currency, loadProposals]);

  if (!open) return null;

  const goProviders = () => {
    if (!amount || Number(amount) <= 0) {
      setError(t('wallet.fund.invalidAmount'));
      return;
    }
    if (!allowedCurrencies.includes(currency)) {
      setError(t('wallet.fund.invalidAmount'));
      return;
    }
    setError(null);
    setStep('providers');
  };

  const pickProposal = (p: FundingProposal) => {
    setSelected(p);
    setError(null);
    setStep('confirm');
  };

  const submit = async () => {
    if (!selected) return;
    if (selected.requires_reference && !reference.trim()) {
      setError(t('wallet.fund.refRequired'));
      return;
    }
    setBusy(true);
    setError(null);
    setOk(null);
    const idem = `ui-collect-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
    const res = await apiFundingCollect({
      proposal_id: selected.id,
      currency,
      amount,
      account_reference: reference.trim() || undefined,
      idempotency_key: idem,
    });
    setBusy(false);
    if (!res.success) {
      setError(res.error || t('wallet.fund.error'));
      return;
    }
    setOk(res.data?.message || t('wallet.fund.success'));
    onSuccess?.(currency);
    setTimeout(() => onClose(), 1200);
  };

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={t('wallet.fund.title')}
      style={{
        position: 'fixed', inset: 0, zIndex: 80,
        background: 'rgba(0,0,0,0.55)',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        padding: 16,
      }}
      onClick={(e) => { if (e.target === e.currentTarget && !busy) onClose(); }}
    >
      <div className="card" style={{ width: '100%', maxWidth: 460, padding: 24, maxHeight: '90vh', overflowY: 'auto' }}>
        <div className="page-label" style={{ marginBottom: 6 }}>{t('wallet.addFunds')}</div>
        <div style={{ fontSize: 18, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 6 }}>
          {t('wallet.fund.title')}
        </div>
        <p style={{ fontSize: 12, color: 'var(--text-mid)', marginBottom: 16 }}>
          {t('wallet.fund.subtitle')}
          {regCountry ? ` (${regCountry})` : ''}
        </p>

        {/* Steps indicator */}
        <div style={{ display: 'flex', gap: 6, marginBottom: 16 }}>
          {(['amount', 'providers', 'confirm'] as Step[]).map((s, i) => (
            <div
              key={s}
              style={{
                flex: 1, height: 3, borderRadius: 2,
                background: step === s || (['providers', 'confirm'].includes(step) && i === 0) || (step === 'confirm' && i <= 1)
                  ? 'var(--cyan)' : 'var(--border)',
              }}
            />
          ))}
        </div>

        {step === 'amount' && (
          <>
            <label style={{ display: 'block', fontSize: 11, color: 'var(--text-dim)', marginBottom: 6 }}>
              {t('wallet.fund.currency')}
            </label>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 14 }}>
              {allowedCurrencies.map((code) => (
                <button
                  key={code}
                  type="button"
                  className={`pill ${currency === code ? 'p-c' : 'p'}`}
                  style={{ cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6 }}
                  onClick={() => setCurrency(code)}
                >
                  <CurrencyLogo code={code} size={18} />
                  {code}
                </button>
              ))}
            </div>
            <label style={{ display: 'block', fontSize: 11, color: 'var(--text-dim)', marginBottom: 6 }}>
              {t('wallet.fund.amount')}
            </label>
            <input
              className="input-field"
              type="number"
              min="0.01"
              step="0.01"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              style={{ width: '100%', marginBottom: 14 }}
            />
            <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
              <button type="button" className="btn btn-ghost" onClick={onClose}>{t('wallet.tab.close')}</button>
              <button type="button" className="btn btn-cyan" onClick={goProviders}>{t('wallet.fund.nextProviders')}</button>
            </div>
          </>
        )}

        {step === 'providers' && (
          <>
            <div style={{ fontSize: 12, color: 'var(--text-mid)', marginBottom: 12 }}>
              {country
                ? t('wallet.fund.forCountry', { country, amount, currency })
                : t('wallet.fund.noCountry')}
            </div>
            {loadingList ? (
              <div style={{ padding: 24, textAlign: 'center' }}><TechLoader size="sm" /></div>
            ) : proposals.length === 0 ? (
              <div className="pill p-g" style={{ marginBottom: 12, display: 'block', width: 'fit-content' }}>
                {listMessage || t('wallet.fund.noProviders')}
              </div>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 14 }}>
                {proposals.map((p) => (
                  <button
                    key={p.id}
                    type="button"
                    onClick={() => pickProposal(p)}
                    className="card"
                    style={{
                      padding: 14, textAlign: 'left', cursor: 'pointer',
                      border: '1px solid var(--border)', background: 'var(--panel2)',
                    }}
                  >
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                      <span style={{ fontSize: 20 }}>{methodIcon(p.method)}</span>
                      <div style={{ flex: 1 }}>
                        <div style={{ fontWeight: 700, color: 'var(--text-bright)', fontSize: 13 }}>{p.label}</div>
                        <div style={{ fontSize: 11, color: 'var(--text-dim)', marginTop: 2 }}>
                          {p.local_currency}
                          {p.operator ? ` · ${p.operator}` : ''}
                          {' · '}{etaLabel(p.eta_minutes, t)}
                          {' · '}{p.estimated_fee_pct.toFixed(1)} %
                          {p.sandbox ? ` · ${t('wallet.fund.sandboxBadge')}` : ''}
                        </div>
                      </div>
                      <span style={{ color: 'var(--cyan)', fontSize: 16 }}>→</span>
                    </div>
                  </button>
                ))}
              </div>
            )}
            <div style={{ display: 'flex', gap: 10, justifyContent: 'space-between' }}>
              <button type="button" className="btn btn-ghost" onClick={() => setStep('amount')}>{t('common.back')}</button>
              <button type="button" className="btn btn-ghost" onClick={onClose}>{t('wallet.tab.close')}</button>
            </div>
          </>
        )}

        {step === 'confirm' && selected && (
          <>
            <div className="card" style={{ padding: 14, marginBottom: 14, background: 'var(--panel2)' }}>
              <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)' }}>
                {methodIcon(selected.method)} {selected.label}
              </div>
              <div style={{ fontSize: 12, color: 'var(--text-mid)', marginTop: 6 }}>
                {amount} {currency}
                {selected.sandbox ? ` · ${t('wallet.fund.sandboxBadge')}` : ''}
              </div>
            </div>
            {selected.requires_reference && (
              <>
                <label style={{ display: 'block', fontSize: 11, color: 'var(--text-dim)', marginBottom: 6 }}>
                  {selected.method === 'bank' ? t('wallet.fund.iban') : t('wallet.fund.phone')}
                </label>
                <input
                  className="input-field"
                  value={reference}
                  onChange={(e) => setReference(e.target.value)}
                  placeholder={selected.method === 'bank' ? 'FR76…' : '+242…'}
                  style={{ width: '100%', marginBottom: 14 }}
                />
              </>
            )}
            <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
              <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => setStep('providers')}>
                {t('common.back')}
              </button>
              <button type="button" className="btn btn-cyan" disabled={busy} onClick={submit}>
                {busy ? '…' : t('wallet.fund.submit')}
              </button>
            </div>
          </>
        )}

        {error && <div className="pill p-r" style={{ marginTop: 12, width: 'fit-content' }}>{error}</div>}
        {ok && <div className="pill p-gr" style={{ marginTop: 12, width: 'fit-content' }}>{ok}</div>}
      </div>
    </div>
  );
}
