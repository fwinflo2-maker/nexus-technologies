import { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { useI18n } from '../../context/I18nContext';
import {
  type WalletTx,
} from '../../api/client';

type FilterType = 'all' | 'send' | 'receive' | 'convert' | 'fx';
type FilterStatus = 'all' | 'completed' | 'pending' | 'failed';

const CURRENCY_META: Record<string, { flag: string; symbol: string }> = {
  EUR:  { flag: '🇪🇺', symbol: '€' },
  USD:  { flag: '🇺🇸', symbol: '$' },
  GBP:  { flag: '🇬🇧', symbol: '£' },
  XAF:  { flag: '🌍', symbol: 'XAF' },
  USDT: { flag: '🔵', symbol: 'USDT' },
};

export default function HistoryPage() {
  const { t } = useI18n();
  const [transactions, setTransactions] = useState<WalletTx[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterType, setFilterType] = useState<FilterType>('all');
  const [filterStatus, setFilterStatus] = useState<FilterStatus>('all');
  const [filterCurrency, setFilterCurrency] = useState<string>('all');

  const fetchTransactions = useCallback(async () => {
    setLoading(true);
    // Données de démonstration
    const demoTxs: WalletTx[] = [
      { id: 1, type: 'send', direction: 'out', label: 'Transfert vers Mobile Money', description: 'Envoi vers MTN CG', amount: 500, currency: 'EUR', status: 'completed', provider: 'Thunes', destination: 'Congo', created_at: new Date().toISOString() },
      { id: 2, type: 'receive', direction: 'in', label: 'Réception virement SEPA', description: null, amount: 1200, currency: 'EUR', status: 'completed', provider: null, destination: null, created_at: new Date(Date.now() - 86400000).toISOString() },
      { id: 3, type: 'convert', direction: 'fx', label: 'Conversion EUR → XAF', description: null, amount: 300, currency: 'EUR', status: 'completed', provider: 'Internal', destination: null, created_at: new Date(Date.now() - 172800000).toISOString() },
      { id: 4, type: 'send', direction: 'out', label: 'Transfert vers IBAN', description: null, amount: 750, currency: 'EUR', status: 'pending', provider: 'Onafriq', destination: 'Cameroun', created_at: new Date(Date.now() - 259200000).toISOString() },
      { id: 5, type: 'receive', direction: 'in', label: 'Réception Mobile Money', description: null, amount: 50000, currency: 'XAF', status: 'completed', provider: 'pawaPay', destination: null, created_at: new Date(Date.now() - 345600000).toISOString() },
    ];
    
    setTransactions(demoTxs);
    setLoading(false);
  }, []);

  useEffect(() => { fetchTransactions(); }, [fetchTransactions]);

  const filteredTxs = transactions.filter(tx => {
    if (filterType !== 'all' && tx.type !== filterType) return false;
    if (filterStatus !== 'all' && tx.status !== filterStatus) return false;
    if (filterCurrency !== 'all' && tx.currency !== filterCurrency) return false;
    return true;
  });

  const formatAmount = (tx: WalletTx): string => {
    const meta = CURRENCY_META[tx.currency];
    const formatted = tx.amount.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const sign = tx.direction === 'in' ? '+' : tx.direction === 'out' ? '−' : '';
    if (meta) return `${sign}${meta.symbol} ${formatted}`;
    return `${sign}${formatted} ${tx.currency}`;
  };

  const formatDate = (iso: string): string => {
    const date = new Date(iso);
    const now = new Date();
    const diffDays = Math.floor((now.getTime() - date.getTime()) / 86400000);
    if (diffDays === 0) return "Aujourd'hui";
    if (diffDays === 1) return 'Hier';
    return date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
  };

  const statusClass = (status: string): string => {
    switch (status) {
      case 'completed': return 'p-gr';
      case 'pending': return 'p-g';
      case 'processing': return 'p-c';
      case 'failed': return 'p-r';
      case 'cancelled': return 'p-v';
      default: return 'p';
    }
  };

  const typeLabel = (type: string): string => {
    const labels: Record<string, string> = {
      send: '↗ ' + t('side_send'),
      receive: '↙ ' + t('side_receive'),
      convert: '⇄ ' + t('side_convert'),
      fx: '⇆ FX',
    };
    return labels[type] || type;
  };

  return (
    <div className="page">
      <motion.div 
        className="page-header animate-up"
        initial={{ opacity: 0, y: 20 }}
        animate={{ opacity: 1, y: 0 }}
      >
        <div className="page-label">NEXUS</div>
        <div className="page-title">{t('history_title')}</div>
        <p style={{ marginTop: 10, fontSize: 13, color: 'var(--text-mid)' }}>
          {t('history_desc')}
        </p>
      </motion.div>

      {/* Filtres */}
      <motion.div 
        className="card" 
        style={{ padding: 16, marginBottom: 20 }}
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        transition={{ delay: 0.1 }}
      >
        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'center' }}>
          <span style={{ fontSize: 12, color: 'var(--text-dim)', textTransform: 'uppercase' }}>{t('history_filters')} :</span>
          
          <select
            value={filterType}
            onChange={(e) => setFilterType(e.target.value as FilterType)}
            className="pill p"
            style={{ cursor: 'pointer', fontSize: 12 }}
          >
            <option value="all">{t('history_all_types')}</option>
            <option value="send">{t('history_sent')}</option>
            <option value="receive">{t('history_received')}</option>
            <option value="convert">{t('history_conversions')}</option>
            <option value="fx">FX</option>
          </select>

          <select
            value={filterStatus}
            onChange={(e) => setFilterStatus(e.target.value as FilterStatus)}
            className="pill p"
            style={{ cursor: 'pointer', fontSize: 12 }}
          >
            <option value="all">{t('history_all_statuses')}</option>
            <option value="completed">{t('route_completed')}</option>
            <option value="pending">{t('dash_pending')}</option>
            <option value="failed">{t('route_reliability')}</option>
          </select>

          <select
            value={filterCurrency}
            onChange={(e) => setFilterCurrency(e.target.value)}
            className="pill p"
            style={{ cursor: 'pointer', fontSize: 12 }}
          >
            <option value="all">{t('history_all_currencies')}</option>
            {Object.keys(CURRENCY_META).map(code => (
              <option key={code} value={code}>{code}</option>
            ))}
          </select>

          {(filterType !== 'all' || filterStatus !== 'all' || filterCurrency !== 'all') && (
            <button
              onClick={() => { setFilterType('all'); setFilterStatus('all'); setFilterCurrency('all'); }}
              className="pill p-r"
              style={{ cursor: 'pointer', fontSize: 12 }}
            >
              ✕ {t('history_reset')}
            </button>
          )}
        </div>
      </motion.div>

      {/* Liste des transactions */}
      {loading ? (
        <div className="card card-hi-c" style={{ padding: 40, textAlign: 'center' }}>
          <div className="nexus-spinner" />
          <p style={{ marginTop: 16, color: 'var(--text-mid)' }}>{t('dash_summary_load')}</p>
        </div>
      ) : filteredTxs.length === 0 ? (
        <motion.div 
          className="card card-hi-c" 
          style={{ padding: 40, textAlign: 'center' }}
          initial={{ opacity: 0, scale: 0.95 }}
          animate={{ opacity: 1, scale: 1 }}
        >
          <div style={{ fontSize: 48, marginBottom: 16 }}>📭</div>
          <h3 style={{ color: 'var(--text-bright)', marginBottom: 8 }}>{t('wallet_no_tx')}</h3>
          <p style={{ color: 'var(--text-mid)' }}>{t('wallet_no_tx')}</p>
        </motion.div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {filteredTxs.map((tx, idx) => (
            <motion.div
              key={tx.id}
              className="card"
              style={{ padding: 16, display: 'flex', alignItems: 'center', gap: 16 }}
              initial={{ opacity: 0, x: -20 }}
              animate={{ opacity: 1, x: 0 }}
              transition={{ delay: idx * 0.05 }}
            >
              {/* Icône type */}
              <div style={{ width: 48, height: 48, borderRadius: 12, background: 'rgba(0,200,255,0.1)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 20 }}>
                {tx.type === 'send' ? '↗' : tx.type === 'receive' ? '↙' : tx.type === 'convert' ? '⇄' : '⇆'}
              </div>

              {/* Infos */}
              <div style={{ flex: 1 }}>
                <div style={{ fontSize: 14, fontWeight: 600, color: 'var(--text-bright)', marginBottom: 2 }}>{tx.label}</div>
                <div style={{ fontSize: 11, color: 'var(--text-mid)' }}>
                  {typeLabel(tx.type)} • {formatDate(tx.created_at)}
                  {tx.provider && ` • ${tx.provider}`}
                  {tx.destination && ` • ${tx.destination}`}
                </div>
              </div>

              {/* Montant et statut */}
              <div style={{ textAlign: 'right' }}>
                <div className="mono" style={{ fontSize: 16, fontWeight: 700, color: tx.direction === 'in' ? 'var(--green)' : 'var(--text-bright)', marginBottom: 4 }}>
                  {formatAmount(tx)}
                </div>
                <span className={`pill ${statusClass(tx.status)}`} style={{ fontSize: 10 }}>
                  {tx.status === 'completed' ? '✓ ' + t('route_completed') : tx.status === 'pending' ? '⏳ ' + t('dash_pending') : tx.status === 'processing' ? '⚙ ' + t('route_in_progress') : '✕ ' + tx.status}
                </span>
              </div>
            </motion.div>
          ))}
        </div>
      )}
    </div>
  );
}
