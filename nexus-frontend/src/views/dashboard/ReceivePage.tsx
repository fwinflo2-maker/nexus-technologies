import { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import {
  apiAccountsList,
  type PaymentAccount,
} from '../../api/client';

/**
 * ReceivePage — Recevoir de l'argent sur NEXUS.
 * 
 * Affiche les coordonnées de réception disponibles selon les capacités de l'utilisateur :
 * - IBAN / compte bancaire
 * - Mobile Money
 * - Wallet crypto
 * - Identifiant NEXUS
 */

const CURRENCY_META: Record<string, { flag: string; symbol: string; label: string }> = {
  EUR:  { flag: '🇪🇺', symbol: '€', label: 'Euro' },
  USD:  { flag: '🇺🇸', symbol: '$', label: 'US Dollar' },
  GBP:  { flag: '🇬🇧', symbol: '£', label: 'Livre Sterling' },
  XAF:  { flag: '🌍', symbol: 'XAF', label: 'Franc CFA (CEMAC)' },
  USDT: { flag: '🔵', symbol: 'USDT', label: 'Tether USD' },
  USDC: { flag: '🔵', symbol: 'USDC', label: 'USD Coin' },
};

export default function ReceivePage() {
  const [selectedCurrency, setSelectedCurrency] = useState<string>('EUR');
  const [accounts, setAccounts] = useState<PaymentAccount[]>([]);
  const [loading, setLoading] = useState(true);
  const [copied, setCopied] = useState<string | null>(null);

  const fetchAccounts = useCallback(async () => {
    setLoading(true);
    const resp = await apiAccountsList('destination');
    if (resp.success && resp.data) {
      setAccounts(resp.data.items);
    }
    setLoading(false);
  }, []);

  useEffect(() => { fetchAccounts(); }, [fetchAccounts]);

  const handleCopy = async (text: string, field: string) => {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(field);
      setTimeout(() => setCopied(null), 2000);
    } catch {
      // Erreur de copie silencieuse
    }
  };

  const filteredAccounts = accounts.filter(acc => 
    !acc.currency || acc.currency === selectedCurrency || acc.currency === 'EUR'
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
        <div className="page-title">Recevoir un transfert</div>
        <p style={{ marginTop: 10, fontSize: 13, color: 'var(--text-mid)', maxWidth: 520 }}>
          Partagez vos coordonnées de réception pour recevoir des fonds depuis l'étranger ou localement.
        </p>
      </motion.div>

      {/* Sélecteur de devise */}
      <motion.div 
        className="card" 
        style={{ padding: 20, marginBottom: 24 }}
        initial="hidden"
        animate="visible"
        variants={fadeInUp}
      >
        <div className="page-label" style={{ marginBottom: 12 }}>Choisissez votre devise de réception</div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {Object.entries(CURRENCY_META).map(([code, meta]) => (
            <button
              key={code}
              onClick={() => setSelectedCurrency(code)}
              className={`pill ${selectedCurrency === code ? 'p-c' : 'p'}`}
              style={{ 
                cursor: 'pointer',
                border: selectedCurrency === code ? '1px solid var(--cyan)' : '1px solid var(--border)',
              }}
            >
              {meta.flag} {code}
            </button>
          ))}
        </div>
      </motion.div>

      {/* Coordonnées de réception */}
      {loading ? (
        <div className="card card-hi-c" style={{ padding: 40, textAlign: 'center' }}>
          <div className="nexus-spinner" />
          <p style={{ marginTop: 16, color: 'var(--text-mid)' }}>Chargement de vos comptes de réception…</p>
        </div>
      ) : filteredAccounts.length === 0 ? (
        <motion.div 
          className="card card-hi-c" 
          style={{ padding: 40, textAlign: 'center' }}
          initial={{ opacity: 0, scale: 0.95 }}
          animate={{ opacity: 1, scale: 1 }}
        >
          <div style={{ fontSize: 48, marginBottom: 16 }}>📭</div>
          <h3 style={{ color: 'var(--text-bright)', marginBottom: 8 }}>Aucun compte de réception</h3>
          <p style={{ color: 'var(--text-mid)', marginBottom: 20, maxWidth: 420 }}>
            Vous n'avez pas encore configuré de compte pour recevoir des fonds en {selectedCurrency}.
            Ajoutez un compte bancaire, Mobile Money ou wallet crypto dans vos paramètres.
          </p>
          <a href="/settings" className="btn btn-cyan">Configurer un compte</a>
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
                    <span style={{ fontSize: 20 }}>{account.kind === 'bank_iban' ? '🏦' : account.kind === 'mobile_money' ? '📱' : '🔐'}</span>
                    <span className="mono" style={{ fontSize: 14, color: 'var(--cyan)' }}>{account.kind.replace('_', ' ').toUpperCase()}</span>
                  </div>
                  <div style={{ fontSize: 16, fontWeight: 600, color: 'var(--text-bright)' }}>{account.label}</div>
                  {account.holder_name && (
                    <div style={{ fontSize: 12, color: 'var(--text-mid)', marginTop: 2 }}>Titulaire : {account.holder_name}</div>
                  )}
                </div>
                {account.is_default && (
                  <span className="pill p-gr" style={{ fontSize: 11 }}>● Compte principal</span>
                )}
              </div>

              {/* Coordonnées */}
              <div style={{ background: 'rgba(0,0,0,0.3)', borderRadius: 8, padding: 12, marginBottom: 12 }}>
                {account.iban_masked && (
                  <div style={{ marginBottom: 8 }}>
                    <div style={{ fontSize: 10, color: 'var(--text-dim)', textTransform: 'uppercase', marginBottom: 4 }}>IBAN</div>
                    <div className="mono" style={{ fontSize: 14, color: 'var(--text-bright)', wordBreak: 'break-all' }}>{account.iban_masked}</div>
                  </div>
                )}
                {account.bic && (
                  <div style={{ marginBottom: 8 }}>
                    <div style={{ fontSize: 10, color: 'var(--text-dim)', textTransform: 'uppercase', marginBottom: 4 }}>BIC / SWIFT</div>
                    <div className="mono" style={{ fontSize: 14, color: 'var(--text-bright)' }}>{account.bic}</div>
                  </div>
                )}
                {account.phone_masked && (
                  <div style={{ marginBottom: 8 }}>
                    <div style={{ fontSize: 10, color: 'var(--text-dim)', textTransform: 'uppercase', marginBottom: 4 }}>Téléphone</div>
                    <div className="mono" style={{ fontSize: 14, color: 'var(--text-bright)' }}>{account.phone_masked}</div>
                  </div>
                )}
                {account.operator && (
                  <div style={{ marginBottom: 8 }}>
                    <div style={{ fontSize: 10, color: 'var(--text-dim)', textTransform: 'uppercase', marginBottom: 4 }}>Opérateur</div>
                    <div style={{ fontSize: 14, color: 'var(--text-bright)' }}>{account.operator}</div>
                  </div>
                )}
                {account.address_masked && (
                  <div>
                    <div style={{ fontSize: 10, color: 'var(--text-dim)', textTransform: 'uppercase', marginBottom: 4 }}>Adresse</div>
                    <div style={{ fontSize: 14, color: 'var(--text-bright)' }}>{account.address_masked}</div>
                  </div>
                )}
              </div>

              {/* Actions */}
              <div style={{ display: 'flex', gap: 8 }}>
                {account.iban_masked && (
                  <button
                    onClick={() => handleCopy(account.iban_masked!, 'iban-' + account.id)}
                    className="btn btn-sm btn-outline"
                    style={{ borderColor: copied?.startsWith('iban-' + account.id) ? 'var(--green)' : undefined, color: copied?.startsWith('iban-' + account.id) ? 'var(--green)' : undefined }}
                  >
                    {copied?.startsWith('iban-' + account.id) ? '✓ Copié' : 'Copier IBAN'}
                  </button>
                )}
                {account.phone_masked && (
                  <button
                    onClick={() => handleCopy(account.phone_masked!, 'phone-' + account.id)}
                    className="btn btn-sm btn-outline"
                    style={{ borderColor: copied?.startsWith('phone-' + account.id) ? 'var(--green)' : undefined, color: copied?.startsWith('phone-' + account.id) ? 'var(--green)' : undefined }}
                  >
                    {copied?.startsWith('phone-' + account.id) ? '✓ Copié' : 'Copier Téléphone'}
                  </button>
                )}
                <button className="btn btn-sm btn-cyan">Partager</button>
              </div>

              {/* Statut */}
              <div style={{ marginTop: 12, paddingTop: 12, borderTop: '1px solid var(--border)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>
                  Statut : <span className={account.verification_status === 'verified' ? 'p-gr' : account.verification_status === 'pending' ? 'p-g' : 'p-r'}>{account.verification_status}</span>
                </div>
                {account.country && (
                  <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>Pays : {account.country.toUpperCase()}</div>
                )}
              </div>
            </motion.div>
          ))}
        </div>
      )}

      {/* QR Code (placeholder pour fonctionnalité future) */}
      {!loading && filteredAccounts.length > 0 && (
        <motion.div 
          className="card" 
          style={{ padding: 20, marginTop: 16 }}
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          transition={{ delay: 0.3 }}
        >
          <div className="page-label" style={{ marginBottom: 12 }}>QR Code de réception</div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
            <div style={{ width: 120, height: 120, background: '#fff', borderRadius: 8, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
              <span style={{ fontSize: 14, color: '#000', textAlign: 'center' }}>QR<br/>Code</span>
            </div>
            <div>
              <div style={{ fontSize: 13, color: 'var(--text-bright)', marginBottom: 4 }}>Scannez ce code pour recevoir</div>
              <div style={{ fontSize: 12, color: 'var(--text-mid)' }}>Fonctionnalité bientôt disponible</div>
            </div>
          </div>
        </motion.div>
      )}
    </div>
  );
}
