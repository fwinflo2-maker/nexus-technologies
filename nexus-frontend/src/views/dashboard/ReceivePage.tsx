import { useState, useEffect, useCallback, useMemo } from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import {
  apiAccountsList,
  type PaymentAccount,
} from '../../api/client';
import { useDashT } from '../../data/dashboard-i18n';
import TechLoader from '../../components/anim/TechLoader';
import { CurrencyLogo } from '../../components/dashboard/CurrencyLogo';

/**
 * ReceivePage — Recevoir de l'argent sur NEXUS.
 *
 * Affiche les comptes de destination (IBAN, Mobile Money, crypto) déjà
 * configurés dans le portefeuille. Les identifiants sensibles ne sont
 * exposés qu’en version masquée par l’API.
 */

const CURRENCY_FALLBACK = ['EUR', 'USD', 'GBP', 'XAF', 'USDT', 'USDC', 'ETH', 'BTC'];

function kindLabel(kind: string, t: (k: string) => string): string {
  if (kind === 'bank_iban') return t('method.bank').toUpperCase();
  if (kind === 'mobile_money') return t('method.mobile_money').toUpperCase();
  if (kind === 'crypto') return t('method.crypto').toUpperCase();
  return kind.toUpperCase();
}

function kindIcon(kind: string): string {
  if (kind === 'bank_iban') return '🏦';
  if (kind === 'mobile_money') return '📱';
  if (kind === 'crypto') return '🔐';
  return '💳';
}

function sharePayload(account: PaymentAccount, t: (k: string, p?: Record<string, string | number>) => string): string {
  const lines = [
    `NEXUS — ${account.label}`,
    account.holder_name ? t('receive.holder', { name: account.holder_name }) : null,
    account.currency ? `${t('receive.pickCurrency')}: ${account.currency}` : null,
    account.iban_masked ? `${t('receive.iban')}: ${account.iban_masked}` : null,
    account.bic ? `${t('receive.bic')}: ${account.bic}` : null,
    account.phone_masked ? `${t('receive.phone')}: ${account.phone_masked}` : null,
    account.operator ? `${t('receive.operator')}: ${account.operator}` : null,
    account.address_masked ? `${t('receive.address')}: ${account.address_masked}` : null,
    t('receive.maskedHint'),
  ].filter(Boolean);
  return lines.join('\n');
}

export default function ReceivePage() {
  const t = useDashT();
  const [selectedCurrency, setSelectedCurrency] = useState<string>('EUR');
  const [accounts, setAccounts] = useState<PaymentAccount[]>([]);
  const [loading, setLoading] = useState(true);
  const [copied, setCopied] = useState<string | null>(null);
  const [shareMsg, setShareMsg] = useState<string | null>(null);

  const fetchAccounts = useCallback(async () => {
    setLoading(true);
    const resp = await apiAccountsList('destination');
    if (resp.success && resp.data) {
      setAccounts(resp.data.items);
    }
    setLoading(false);
  }, []);

  useEffect(() => { fetchAccounts(); }, [fetchAccounts]);

  const currencyCodes = useMemo(() => {
    const fromAccounts = accounts
      .map((a) => (a.currency || '').toUpperCase())
      .filter(Boolean);
    return Array.from(new Set([...CURRENCY_FALLBACK, ...fromAccounts]));
  }, [accounts]);

  useEffect(() => {
    if (currencyCodes.length === 0) return;
    if (!currencyCodes.includes(selectedCurrency)) {
      setSelectedCurrency(currencyCodes[0]);
    }
  }, [currencyCodes, selectedCurrency]);

  const handleCopy = async (text: string, field: string) => {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(field);
      setTimeout(() => setCopied(null), 2000);
    } catch {
      // Copie silencieuse en cas d’échec (permissions navigateur)
    }
  };

  const handleShare = async (account: PaymentAccount) => {
    const text = sharePayload(account, t);
    try {
      if (typeof navigator.share === 'function') {
        await navigator.share({ title: `NEXUS — ${account.label}`, text });
        setShareMsg(t('receive.shareOk'));
      } else {
        await navigator.clipboard.writeText(text);
        setShareMsg(t('receive.shareCopied'));
      }
    } catch {
      try {
        await navigator.clipboard.writeText(text);
        setShareMsg(t('receive.shareCopied'));
      } catch {
        setShareMsg(t('receive.shareFail'));
      }
    }
    setTimeout(() => setShareMsg(null), 2500);
  };

  // Filtre strict : devise exacte uniquement (plus de fuite EUR forcée).
  const filteredAccounts = accounts.filter(
    (acc) => (acc.currency || '').toUpperCase() === selectedCurrency.toUpperCase(),
  );

  const fadeInUp = {
    hidden: { opacity: 0, y: 20 },
    visible: { opacity: 1, y: 0, transition: { duration: 0.4 } },
  };

  return (
    <div className="page">
      <motion.div
        className="page-header animate-up"
        initial="hidden"
        animate="visible"
        variants={fadeInUp}
      >
        <div className="page-label">NEXUS</div>
        <div className="page-title">{t('receive.title')}</div>
        <p style={{ marginTop: 10, fontSize: 13, color: 'var(--text-mid)', maxWidth: 520 }}>
          {t('receive.subtitle')}
        </p>
      </motion.div>

      <motion.div
        className="card"
        style={{ padding: 20, marginBottom: 24 }}
        initial="hidden"
        animate="visible"
        variants={fadeInUp}
      >
        <div className="page-label" style={{ marginBottom: 12 }}>{t('receive.pickCurrency')}</div>
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
          {currencyCodes.map((code) => (
            <button
              key={code}
              type="button"
              onClick={() => setSelectedCurrency(code)}
              className={`pill ${selectedCurrency === code ? 'p-c' : 'p'}`}
              style={{
                cursor: 'pointer',
                display: 'inline-flex',
                alignItems: 'center',
                gap: 8,
                border: selectedCurrency === code ? '1px solid var(--cyan)' : '1px solid var(--border)',
              }}
              aria-pressed={selectedCurrency === code}
            >
              <CurrencyLogo code={code} size={22} />
              {code}
            </button>
          ))}
        </div>
        <p style={{ marginTop: 12, fontSize: 11, color: 'var(--text-dim)' }}>
          {t('receive.maskedHint')}
        </p>
      </motion.div>

      {shareMsg && (
        <div className="pill p-gr" style={{ marginBottom: 12, width: 'fit-content' }}>{shareMsg}</div>
      )}

      {loading ? (
        <div className="card card-hi-c" style={{ padding: 40, textAlign: 'center' }}>
          <TechLoader />
          <p style={{ marginTop: 16, color: 'var(--text-mid)' }}>{t('receive.loading')}</p>
        </div>
      ) : filteredAccounts.length === 0 ? (
        <motion.div
          className="card card-hi-c"
          style={{ padding: 40, textAlign: 'center' }}
          initial={{ opacity: 0, scale: 0.95 }}
          animate={{ opacity: 1, scale: 1 }}
        >
          <div style={{ fontSize: 48, marginBottom: 16 }}>📭</div>
          <h3 style={{ color: 'var(--text-bright)', marginBottom: 8 }}>{t('receive.empty.title')}</h3>
          <p style={{ color: 'var(--text-mid)', marginBottom: 20, maxWidth: 420, marginInline: 'auto' }}>
            {t('receive.empty.text', { currency: selectedCurrency })}
          </p>
          <Link to="/wallet?tab=destinations" className="btn btn-cyan">
            {t('receive.configure')}
          </Link>
        </motion.div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          {filteredAccounts.map((account, idx) => (
            <motion.div
              key={account.id}
              className="card"
              style={{ padding: 20 }}
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: idx * 0.08 }}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 16 }}>
                <div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
                    <span style={{ fontSize: 20 }}>{kindIcon(account.kind)}</span>
                    <span className="mono" style={{ fontSize: 14, color: 'var(--cyan)' }}>
                      {kindLabel(account.kind, t)}
                    </span>
                  </div>
                  <div style={{ fontSize: 16, fontWeight: 600, color: 'var(--text-bright)' }}>{account.label}</div>
                  {account.holder_name && (
                    <div style={{ fontSize: 12, color: 'var(--text-mid)', marginTop: 2 }}>
                      {t('receive.holder', { name: account.holder_name })}
                    </div>
                  )}
                </div>
                {account.is_default && (
                  <span className="pill p-gr" style={{ fontSize: 11 }}>{t('receive.main')}</span>
                )}
              </div>

              <div style={{ background: 'rgba(0,0,0,0.3)', borderRadius: 8, padding: 12, marginBottom: 12 }}>
                {account.iban_masked && (
                  <div style={{ marginBottom: 8 }}>
                    <div style={{ fontSize: 10, color: 'var(--text-dim)', textTransform: 'uppercase', marginBottom: 4 }}>{t('receive.iban')}</div>
                    <div className="mono" style={{ fontSize: 14, color: 'var(--text-bright)', wordBreak: 'break-all' }}>{account.iban_masked}</div>
                  </div>
                )}
                {account.bic && (
                  <div style={{ marginBottom: 8 }}>
                    <div style={{ fontSize: 10, color: 'var(--text-dim)', textTransform: 'uppercase', marginBottom: 4 }}>{t('receive.bic')}</div>
                    <div className="mono" style={{ fontSize: 14, color: 'var(--text-bright)' }}>{account.bic}</div>
                  </div>
                )}
                {account.phone_masked && (
                  <div style={{ marginBottom: 8 }}>
                    <div style={{ fontSize: 10, color: 'var(--text-dim)', textTransform: 'uppercase', marginBottom: 4 }}>{t('receive.phone')}</div>
                    <div className="mono" style={{ fontSize: 14, color: 'var(--text-bright)' }}>{account.phone_masked}</div>
                  </div>
                )}
                {account.operator && (
                  <div style={{ marginBottom: 8 }}>
                    <div style={{ fontSize: 10, color: 'var(--text-dim)', textTransform: 'uppercase', marginBottom: 4 }}>{t('receive.operator')}</div>
                    <div style={{ fontSize: 14, color: 'var(--text-bright)' }}>{account.operator}</div>
                  </div>
                )}
                {account.address_masked && (
                  <div>
                    <div style={{ fontSize: 10, color: 'var(--text-dim)', textTransform: 'uppercase', marginBottom: 4 }}>{t('receive.address')}</div>
                    <div style={{ fontSize: 14, color: 'var(--text-bright)', wordBreak: 'break-all' }}>{account.address_masked}</div>
                  </div>
                )}
              </div>

              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                {account.iban_masked && (
                  <button
                    type="button"
                    onClick={() => handleCopy(account.iban_masked!, 'iban-' + account.id)}
                    className="btn btn-sm btn-outline"
                    style={{ borderColor: copied === 'iban-' + account.id ? 'var(--green)' : undefined, color: copied === 'iban-' + account.id ? 'var(--green)' : undefined }}
                  >
                    {copied === 'iban-' + account.id ? `✓ ${t('receive.copied')}` : t('receive.copyIban')}
                  </button>
                )}
                {account.phone_masked && (
                  <button
                    type="button"
                    onClick={() => handleCopy(account.phone_masked!, 'phone-' + account.id)}
                    className="btn btn-sm btn-outline"
                    style={{ borderColor: copied === 'phone-' + account.id ? 'var(--green)' : undefined, color: copied === 'phone-' + account.id ? 'var(--green)' : undefined }}
                  >
                    {copied === 'phone-' + account.id ? `✓ ${t('receive.copied')}` : t('receive.copyPhone')}
                  </button>
                )}
                {account.address_masked && (
                  <button
                    type="button"
                    onClick={() => handleCopy(account.address_masked!, 'addr-' + account.id)}
                    className="btn btn-sm btn-outline"
                    style={{ borderColor: copied === 'addr-' + account.id ? 'var(--green)' : undefined, color: copied === 'addr-' + account.id ? 'var(--green)' : undefined }}
                  >
                    {copied === 'addr-' + account.id ? `✓ ${t('receive.copied')}` : t('receive.copyAddress')}
                  </button>
                )}
                <button
                  type="button"
                  className="btn btn-sm btn-cyan"
                  onClick={() => handleShare(account)}
                >
                  {t('receive.share')}
                </button>
              </div>

              <div style={{ marginTop: 12, paddingTop: 12, borderTop: '1px solid var(--border)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>
                  {t('receive.status')} :{' '}
                  <span className={account.verification_status === 'verified' ? 'p-gr' : account.verification_status === 'pending' ? 'p-g' : 'p-r'}>
                    {t(`status.${account.verification_status}`)}
                  </span>
                </div>
                {account.country && (
                  <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>
                    {t('receive.countryLabel')} : {account.country.toUpperCase()}
                  </div>
                )}
              </div>
            </motion.div>
          ))}
        </div>
      )}

      {!loading && filteredAccounts.length > 0 && (
        <motion.div
          className="card"
          style={{ padding: 20, marginTop: 16 }}
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          transition={{ delay: 0.3 }}
        >
          <div className="page-label" style={{ marginBottom: 12 }}>{t('receive.qrTitle')}</div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
            <div style={{ width: 120, height: 120, background: 'rgba(255,255,255,0.06)', borderRadius: 8, display: 'flex', alignItems: 'center', justifyContent: 'center', border: '1px dashed var(--border)' }}>
              <span className="pill p-g" style={{ fontSize: 10 }}>{t('receive.qrSoon')}</span>
            </div>
            <div>
              <div style={{ fontSize: 13, color: 'var(--text-bright)', marginBottom: 4 }}>{t('receive.qrScan')}</div>
              <div style={{ fontSize: 12, color: 'var(--text-mid)' }}>{t('receive.qrSoon')}</div>
            </div>
          </div>
        </motion.div>
      )}
    </div>
  );
}
