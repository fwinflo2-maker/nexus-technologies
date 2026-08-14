import { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import {
  apiTransfersList,
  type TransferTx,
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
  const [transactions, setTransactions] = useState<TransferTx[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [filterType, setFilterType] = useState<FilterType>('all');
  const [filterStatus, setFilterStatus] = useState<FilterStatus>('all');
  const [filterCurrency, setFilterCurrency] = useState<string>('all');

  // L'historique provient EXCLUSIVEMENT de l'API (GET /api/transfers).
  // Aucune donnée fictive : si l'utilisateur n'a pas de transactions,
  // l'état vide est affiché proprement.
  const fetchTransactions = useCallback(async () => {
    setLoading(true);
    setError(null);

    const res = await apiTransfersList({ per_page: 100 });

    if (!res.success || !res.data) {
      setError(res.error || 'Impossible de charger l\'historique.');
      setLoading(false);
      return;
    }

    setTransactions(res.data.items);
    setLoading(false);
  }, []);

  useEffect(() => { fetchTransactions(); }, [fetchTransactions]);

  const filteredTxs = transactions.filter(tx => {
    if (filterType !== 'all' && tx.type !== filterType) return false;
    if (filterStatus !== 'all' && tx.status !== filterStatus) return false;
    if (filterCurrency !== 'all' && tx.currency !== filterCurrency) return false;
    return true;
  });

  const formatAmount = (tx: TransferTx): string => {
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

  const statusLabel = (status: string): string => {
    switch (status) {
      case 'completed': return '✓ Terminé';
      case 'pending': return '⏳ En attente';
      case 'processing': return '⚙ En cours';
      case 'failed': return '✕ Échoué';
      case 'cancelled': return 'Annulé';
      default: return status;
    }
  };

  const typeLabel = (type: string): string => {
    const labels: Record<string, string> = {
      send: '↗ Envoi',
      receive: '↙ Réception',
      convert: '⇄ Conversion',
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
        <div className="page-title">Historique des transactions</div>
        <p style={{ marginTop: 10, fontSize: 13, color: 'var(--text-mid)' }}>
          Consultez toutes vos opérations financières enregistrées dans le ledger.
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
          <span style={{ fontSize: 12, color: 'var(--text-dim)', textTransform: 'uppercase' }}>Filtres :</span>
          
          <select
            value={filterType}
            onChange={(e) => setFilterType(e.target.value as FilterType)}
            className="pill p"
            style={{ cursor: 'pointer', fontSize: 12 }}
          >
            <option value="all">Tous les types</option>
            <option value="send">Envoyés</option>
            <option value="receive">Reçus</option>
            <option value="convert">Conversions</option>
            <option value="fx">FX</option>
          </select>

          <select
            value={filterStatus}
            onChange={(e) => setFilterStatus(e.target.value as FilterStatus)}
            className="pill p"
            style={{ cursor: 'pointer', fontSize: 12 }}
          >
            <option value="all">Tous les statuts</option>
            <option value="completed">Terminés</option>
            <option value="pending">En attente</option>
            <option value="failed">Échoués</option>
          </select>

          <select
            value={filterCurrency}
            onChange={(e) => setFilterCurrency(e.target.value)}
            className="pill p"
            style={{ cursor: 'pointer', fontSize: 12 }}
          >
            <option value="all">Toutes devises</option>
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
              ✕ Reset
            </button>
          )}

          <button
            onClick={fetchTransactions}
            className="pill p-c"
            style={{ cursor: 'pointer', fontSize: 12, marginLeft: 'auto' }}
          >
            ↻ Actualiser
          </button>
        </div>
      </motion.div>

      {/* États : chargement / erreur / vide / liste */}
      {loading ? (
        <div className="card card-hi-c" style={{ padding: 40, textAlign: 'center' }}>
          <div className="nexus-spinner" />
          <p style={{ marginTop: 16, color: 'var(--text-mid)' }}>Chargement de l'historique…</p>
        </div>
      ) : error ? (
        <div className="card card-hi-g" style={{ padding: 40, textAlign: 'center' }}>
          <div style={{ fontSize: 34, marginBottom: 12 }}>⚠️</div>
          <h3 style={{ color: 'var(--text-bright)', marginBottom: 8 }}>Erreur de chargement</h3>
          <p style={{ color: 'var(--text-mid)', marginBottom: 16 }}>{error}</p>
          <button className="se-cta" onClick={fetchTransactions} style={{ fontSize: 12 }}>↻ Réessayer</button>
        </div>
      ) : filteredTxs.length === 0 ? (
        <motion.div 
          className="card card-hi-c" 
          style={{ padding: 40, textAlign: 'center' }}
          initial={{ opacity: 0, scale: 0.95 }}
          animate={{ opacity: 1, scale: 1 }}
        >
          <div style={{ fontSize: 48, marginBottom: 16 }}>📭</div>
          <h3 style={{ color: 'var(--text-bright)', marginBottom: 8 }}>No transactions yet</h3>
          <p style={{ color: 'var(--text-mid)' }}>
            {transactions.length === 0
              ? 'Aucune transaction pour le moment. Lancez un premier envoi pour la voir apparaître ici.'
              : 'Aucune transaction ne correspond à vos filtres.'}
          </p>
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
                <div style={{ fontSize: 14, fontWeight: 600, color: 'var(--text-bright)', marginBottom: 2 }}>
                  {tx.label}
                  {tx.route_id && <span className="mono" style={{ fontSize: 10, color: 'var(--text-dim)', marginLeft: 8 }}>route {tx.route_id}</span>}
                </div>
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
                  {statusLabel(tx.status)}
                </span>
              </div>
            </motion.div>
          ))}
        </div>
      )}
    </div>
  );
}
