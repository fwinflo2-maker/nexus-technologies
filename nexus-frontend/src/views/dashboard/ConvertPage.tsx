import { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import {
  apiWalletsList,
  apiIntentCoverage,
  apiCreateQuote,
  type WalletState,
  type IntentCoverageData,
  type QuoteData,
} from '../../api/client';

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
  const [coverage, setCoverage] = useState<IntentCoverageData | null>(null);
  const [fromCurrency, setFromCurrency] = useState<string>('EUR');
  const [toCurrency, setToCurrency] = useState<string>('XAF');
  const [amount, setAmount] = useState<string>('');
  const [quote, setQuote] = useState<QuoteData | null>(null);
  const [loading, setLoading] = useState(false);
  const [converting, setConverting] = useState(false);
  const [countdown, setCountdown] = useState<number>(0);

  const fetchData = useCallback(async () => {
    const [walletRes, coverageRes] = await Promise.all([
      apiWalletsList(),
      apiIntentCoverage(),
    ]);
    if (walletRes.success && walletRes.data) {
      setWallets(walletRes.data.wallets.filter(w => w.has_funds));
    }
    if (coverageRes.success && coverageRes.data) {
      setCoverage(coverageRes.data);
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
    setConverting(true);
    // Simulation d'exécution - à connecter au backend
    setTimeout(() => {
      setConverting(false);
      setAmount('');
      setQuote(null);
    }, 2000);
  };

  const availableCurrencies = wallets.map(w => w.currency);

  return (
    <div className="page">
      <motion.div 
        className="page-header animate-up"
        initial={{ opacity: 0, y: 20 }}
        animate={{ opacity: 1, y: 0 }}
      >
        <div className="page-label">NEXUS</div>
        <div className="page-title">Convertir des devises</div>
        <p style={{ marginTop: 10, fontSize: 13, color: 'var(--text-mid)' }}>
          Convertissez instantanément entre vos devises aux meilleurs taux.
        </p>
      </motion.div>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 16, maxWidth: 520 }}>
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
      </div>
    </div>
  );
}
