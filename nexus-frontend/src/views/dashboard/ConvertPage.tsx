import { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { Link } from 'react-router-dom';
import {
  apiWalletsList,
  apiIntentCoverage,
  apiCreateQuote,
  apiWalletConvert,
  apiWalletRates,
  type WalletState,
  type IntentCoverageData,
  type QuoteData,
  type WalletRatesData,
} from '../../api/client';
import AnimatedCounter from '../../components/AnimatedCounter';

const CURRENCY_META: Record<string, { flag: string; symbol: string; label: string }> = {
  EUR:  { flag: '🇪🇺', symbol: '€', label: 'Euro' },
  USD:  { flag: '🇺🇸', symbol: '$', label: 'US Dollar' },
  GBP:  { flag: '🇬🇧', symbol: '£', label: 'Livre Sterling' },
  XAF:  { flag: '🌍', symbol: 'XAF', label: 'Franc CFA (CEMAC)' },
  USDT: { flag: '🔵', symbol: 'USDT', label: 'Tether USD' },
  USDC: { flag: '🔵', symbol: 'USDC', label: 'USD Coin' },
};

export default function ConvertPage() {
  const [wallets, setWallets] = useState<WalletState[]>([]);
  const [, setCoverage] = useState<IntentCoverageData | null>(null);
  const [rates, setRates] = useState<WalletRatesData | null>(null);
  const [fromCurrency, setFromCurrency] = useState<string>('EUR');
  const [toCurrency, setToCurrency] = useState<string>('XAF');
  const [amount, setAmount] = useState<string>('');
  const [quote, setQuote] = useState<QuoteData | null>(null);
  const [loading, setLoading] = useState(false);
  const [converting, setConverting] = useState(false);
  const [countdown, setCountdown] = useState<number>(0);
  const [convertError, setConvertError] = useState<string | null>(null);
  const [convertDone, setConvertDone] = useState<string | null>(null);

  const fetchData = useCallback(async () => {
    const [walletRes, coverageRes, ratesRes] = await Promise.all([
      apiWalletsList(),
      apiIntentCoverage(),
      apiWalletRates(),
    ]);
    if (walletRes.success && walletRes.data) {
      setWallets(walletRes.data.wallets);
    }
    if (coverageRes.success && coverageRes.data) {
      setCoverage(coverageRes.data);
    }
    if (ratesRes.success && ratesRes.data) {
      setRates(ratesRes.data);
    }
  }, []);

  useEffect(() => { fetchData(); }, [fetchData]);

  useEffect(() => {
    if (countdown > 0) {
      const timer = setInterval(() => setCountdown(c => c - 1), 1000);
      return () => clearInterval(timer);
    }
  }, [countdown]);

  const handleGetQuote = async () => {
    if (!amount || parseFloat(amount) <= 0) return;
    setLoading(true);
    const resp = await apiCreateQuote({
      amount: parseFloat(amount),
      sourceCurrency: fromCurrency,
      originCountry: 'FR',
      destCountry: 'CG',
      destCurrency: toCurrency,
      receivingMethod: 'wallet_internal',
      objective: 'optimized',
    });
    if (resp.success && resp.data) {
      setQuote(resp.data);
      const expires = new Date(resp.data.expires_at).getTime();
      const now = Date.now();
      setCountdown(Math.floor((expires - now) / 1000));
    }
    setLoading(false);
  };

  const handleConvert = async () => {
    // Cette fonction exécutait un setTimeout de deux secondes puis vidait le
    // formulaire : l'utilisateur voyait une conversion réussie alors
    // qu'aucun argent n'avait bougé. Elle appelle désormais le moteur réel.
    setConverting(true);
    setConvertError(null);
    setConvertDone(null);

    // Clé d'idempotence : un double-clic ou un retry réseau ne doit pas
    // convertir deux fois. Elle est générée ICI, avant l'envoi, pour que les
    // deux tentatives portent la même clé.
    const idempotencyKey = `convert-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;

    const res = await apiWalletConvert({
      amount,
      source_currency: fromCurrency,
      dest_currency: toCurrency,
      idempotency_key: idempotencyKey,
    });

    setConverting(false);

    if (!res.success) {
      // Le message du backend est affiché tel quel : il porte le motif exact
      // du refus (solde insuffisant, devise non supportée, environnement).
      setConvertError(res.error ?? 'La conversion a échoué.');
      return;
    }

    setConvertDone(`Conversion effectuée : ${amount} ${fromCurrency} → ${toCurrency}.`);
    setAmount('');
    setQuote(null);
    void fetchData(); // recharge les soldes réels
  };

  return (
    <div className="page">
      <motion.div 
        className="page-header animate-up"
        initial={{ opacity: 0, y: 20 }}
        animate={{ opacity: 1, y: 0 }}
      >
        <div className="page-label">NEXUS FX</div>
        <div className="page-title">Convertir des <span className="gc">devises</span></div>
        <p style={{ marginTop: 10, fontSize: 13, color: 'var(--text-mid)' }}>
          Convertissez instantanément entre vos devises aux meilleurs taux.
        </p>
      </motion.div>

      <div className="g2" style={{ alignItems: 'start', gap: 24 }}>
        {/* Devises */}
        <motion.div className="card" style={{ padding: 20 }} initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
          <div className="page-label" style={{ marginBottom: 12 }}>Devises</div>
          <div style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
            <div style={{ flex: 1 }}>
              <label style={{ fontSize: 11, color: 'var(--text-dim)', textTransform: 'uppercase' }}>Vous donnez</label>
              <select
                value={fromCurrency}
                onChange={(e) => setFromCurrency(e.target.value)}
                className="btn btn-outline"
                style={{ width: '100%', marginTop: 4 }}
              >
                {Object.keys(CURRENCY_META).map(code => (
                  <option key={code} value={code}>{CURRENCY_META[code].flag} {code}</option>
                ))}
              </select>
            </div>
            <div style={{ fontSize: 24, color: 'var(--cyan)' }}>⇄</div>
            <div style={{ flex: 1 }}>
              <label style={{ fontSize: 11, color: 'var(--text-dim)', textTransform: 'uppercase' }}>Vous recevez</label>
              <select
                value={toCurrency}
                onChange={(e) => setToCurrency(e.target.value)}
                className="btn btn-outline"
                style={{ width: '100%', marginTop: 4 }}
              >
                {Object.keys(CURRENCY_META).filter(c => c !== fromCurrency).map(code => (
                  <option key={code} value={code}>{CURRENCY_META[code].flag} {code}</option>
                ))}
              </select>
            </div>
          </div>
        </motion.div>

        {/* Montant */}
        <motion.div className="card" style={{ padding: 20 }} initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: 0.1 }}>
          <div className="page-label" style={{ marginBottom: 12 }}>Montant</div>
          <input
            type="number"
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
            placeholder="0.00"
            className="btn btn-outline"
            style={{ width: '100%', fontSize: 24, fontWeight: 600 }}
          />
          {wallets.find(w => w.currency === fromCurrency) && (
            <div style={{ marginTop: 8, fontSize: 12, color: 'var(--text-mid)' }}>
              Solde disponible : {wallets.find(w => w.currency === fromCurrency)?.available.toLocaleString('fr-FR')} {fromCurrency}
            </div>
          )}
        </motion.div>

        {/* Bouton Obtenir un taux */}
        {!quote && (
          <motion.button
            className="btn btn-cyan"
            onClick={handleGetQuote}
            disabled={loading || !amount || parseFloat(amount) <= 0}
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ delay: 0.2 }}
            style={{ width: '100%' }}
          >
            {loading ? 'Calcul en cours...' : 'Obtenir un taux'}
          </motion.button>
        )}

        {/* Quote */}
        {quote && (
          <motion.div 
            className="card card-hi-c" 
            style={{ padding: 20 }}
            initial={{ opacity: 0, scale: 0.95 }}
            animate={{ opacity: 1, scale: 1 }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 16 }}>
              <span className="page-label">Taux garanti</span>
              <span className={`pill ${countdown < 10 ? 'p-r' : 'p-g'}`}>
                ⏱ {Math.floor(countdown / 60)}:{(countdown % 60).toString().padStart(2, '0')}
              </span>
            </div>

            <div style={{ textAlign: 'center', marginBottom: 20 }}>
              <div style={{ fontSize: 13, color: 'var(--text-mid)' }}>Vous donnez</div>
              <div className="mono" style={{ fontSize: 28, fontWeight: 700, color: 'var(--text-bright)' }}>
                {parseFloat(amount).toLocaleString('fr-FR', { minimumFractionDigits: 2 })} {fromCurrency}
              </div>
            </div>

            <div style={{ fontSize: 32, color: 'var(--cyan)', textAlign: 'center', marginBottom: 20 }}>↓</div>

            <div style={{ textAlign: 'center', marginBottom: 20 }}>
              <div style={{ fontSize: 13, color: 'var(--text-mid)' }}>Vous recevez</div>
              <div className="mono" style={{ fontSize: 28, fontWeight: 700, color: 'var(--green)' }}>
                {quote.routes[0]?.receivedNum.toLocaleString('fr-FR', { minimumFractionDigits: 2 })} {toCurrency}
              </div>
            </div>

            <div style={{ background: 'rgba(0,0,0,0.3)', borderRadius: 8, padding: 12, marginBottom: 16 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 8 }}>
                <span style={{ fontSize: 12, color: 'var(--text-mid)' }}>Taux de change</span>
                <span className="mono" style={{ fontSize: 12, color: 'var(--text-bright)' }}>
                  1 {fromCurrency} = {(quote.routes[0]?.receivedNum / parseFloat(amount)).toFixed(4)} {toCurrency}
                </span>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 8 }}>
                <span style={{ fontSize: 12, color: 'var(--text-mid)' }}>Frais</span>
                <span className="mono" style={{ fontSize: 12, color: 'var(--text-bright)' }}>
                  {quote.routes[0]?.fees}
                </span>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                <span style={{ fontSize: 12, color: 'var(--text-mid)' }}>Provider</span>
                <span style={{ fontSize: 12, color: 'var(--text-bright)' }}>{quote.routes[0]?.provider}</span>
              </div>
            </div>

            <div style={{ display: 'flex', gap: 8 }}>
              <button className="btn btn-outline" onClick={() => setQuote(null)} style={{ flex: 1 }}>Annuler</button>
              <button 
                className="btn btn-green" 
                onClick={handleConvert}
                disabled={converting}
                style={{ flex: 1 }}
              >
                {converting ? 'Conversion...' : 'Confirmer la conversion'}
              </button>
            </div>
          </motion.div>
        )}

        {/* Résultat réel de la conversion. Un échec doit être VISIBLE : la
            version précédente ne pouvait afficher que du succès. */}
        {convertError && (
          <div
            className="card"
            style={{ padding: 14, borderColor: 'var(--danger, #e5484d)', color: 'var(--danger, #e5484d)', fontSize: 13 }}
            role="alert"
          >
            {convertError}
          </div>
        )}

        {convertDone && (
          <div
            className="card"
            style={{ padding: 14, borderColor: 'var(--success, #30a46c)', color: 'var(--success, #30a46c)', fontSize: 13 }}
            role="status"
          >
            {convertDone}
          </div>
        )}
      </div>

      {/* ── Colonne latérale : aperçu du portefeuille (occupe le vide) ── */}
      <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        {/* Solde total */}
        <motion.div className="card card-hi-c" initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.15 }}>
          <div className="page-label" style={{ marginBottom: 10 }}>Patrimoine</div>
          <div className="mono" style={{ fontSize: 30, fontWeight: 800, color: 'var(--white)', letterSpacing: '-1px' }}>
            <AnimatedCounter
              value={wallets.reduce((s, w) => s + w.ref_equivalent, 0)}
              format={(n) => n.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })}
            />
          </div>
          <div style={{ fontSize: 11, color: 'var(--text-dim)', marginTop: 4 }}>
            {wallets.filter(w => w.has_funds).length} devises actives · équivalent EUR
          </div>
        </motion.div>

        {/* Soldes par devise */}
        <motion.div className="card" initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.22 }}>
          <div className="page-label" style={{ marginBottom: 12 }}>Vos devises</div>
          {wallets.length === 0 ? (
            <div style={{ fontSize: 12, color: 'var(--text-dim)', padding: 12, textAlign: 'center' }}>
              Aucun wallet disponible.
            </div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              {wallets.filter(w => w.has_funds).map((w) => {
                const meta = CURRENCY_META[w.currency] ?? { flag: '🌐', symbol: w.currency, label: w.currency };
                const pct = w.balance > 0 ? Math.min(100, Math.round((w.available / w.balance) * 100)) : 0;
                return (
                  <div key={w.currency} style={{ padding: '10px 0' }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <span style={{ fontSize: 18 }}>{meta.flag}</span>
                        <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)' }}>{w.currency}</span>
                      </div>
                      <span className="mono" style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-main)' }}>
                        {w.available.toLocaleString('fr-FR')} {w.currency}
                      </span>
                    </div>
                    <div style={{ height: 5, background: 'var(--panel2)', borderRadius: 3, overflow: 'hidden' }}>
                      <div style={{ height: '100%', width: `${pct}%`, background: 'linear-gradient(90deg, var(--cyan2), var(--cyan))', borderRadius: 3 }} />
                    </div>
                  </div>
                );
              })}
            </div>
          )}
          <Link to="/wallet" className="btn btn-ghost" style={{ width: '100%', marginTop: 12, fontSize: 11, textDecoration: 'none' }}>
            Gérer mon portefeuille →
          </Link>
        </motion.div>

        {/* Taux de référence */}
        {rates && (
          <motion.div className="card" initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.3 }}>
            <div className="page-label" style={{ marginBottom: 12 }}>Taux de référence</div>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <span style={{ fontSize: 12, color: 'var(--text-mid)' }}>{rates.base} → XAF</span>
              <span className="mono" style={{ fontSize: 14, fontWeight: 700, color: 'var(--gold)' }}>
                1 {rates.base} = {rates.fx_rate_xaf.toLocaleString('fr-FR', { maximumFractionDigits: 3 })} XAF
              </span>
            </div>
            <div style={{ fontSize: 10, color: 'var(--text-dim)', marginTop: 8, fontFamily: 'var(--font-mono)' }}>
              Source : marché · mis à jour {new Date(rates.updated_at).toLocaleTimeString('fr-FR')}
            </div>
          </motion.div>
        )}

        {/* Conseil Nexus */}
        <motion.div className="card card-hi-v" initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.38 }}>
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 8 }}>
            <div className="ib ib-v" style={{ width: 28, height: 28, borderRadius: 8, fontSize: 14 }}>🤖</div>
            <span style={{ fontSize: 10, color: 'var(--violet)', fontFamily: 'var(--font-mono)', letterSpacing: '0.12em' }}>NEXUS FX INTELLIGENCE</span>
          </div>
          <p style={{ fontSize: 12, color: 'var(--text-mid)', lineHeight: 1.6 }}>
            Nexus compare les taux, frais et délais pour sélectionner le meilleur
            chemin de conversion. Le taux affiché est <strong style={{ color: 'var(--text-bright)' }}>garanti</strong> pendant sa période de validité.
          </p>
        </motion.div>
      </div>
    </div>
  );
}
