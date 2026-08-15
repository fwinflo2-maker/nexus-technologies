import { useState, useEffect, useCallback } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { Link } from 'react-router-dom';
import {
  apiTransfersList,
  type TransferTx,
} from '../../api/client';
import EmptyState from '../../components/EmptyState';

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
  const [selected, setSelected] = useState<TransferTx | null>(null);

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
      send: 'Envoi',
      receive: 'Réception',
      convert: 'Conversion',
      fx: 'FX',
    };
    return labels[type] || type;
  };

  const typeIcon = (type: string): string => {
    const icons: Record<string, string> = { send: '↗', receive: '↙', convert: '⇄', fx: '⇆' };
    return icons[type] || '◆';
  };

  const typeIb = (type: string): string => {
    const cls: Record<string, string> = {
      send: 'ib-c',
      receive: 'ib-gr',
      convert: 'ib-v',
      fx: 'ib-g',
    };
    return cls[type] || 'ib-c';
  };

  return (
    <div className="page">
      <motion.div 
        className="page-header animate-up"
        initial={{ opacity: 0, y: 20 }}
        animate={{ opacity: 1, y: 0 }}
      >
        <div className="page-label">NEXUS LEDGER</div>
        <div className="page-title">Historique des <span className="gc">transactions</span></div>
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
        <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }}>
          <div className="card card-hi-c" style={{ padding: 8 }}>
            <EmptyState
              icon="📭"
              title={transactions.length === 0 ? 'Aucune transaction' : 'Aucun résultat'}
              subtitle={transactions.length === 0
                ? 'Lancez un premier envoi pour voir vos opérations apparaître ici, tracées dans le ledger.'
                : 'Aucune transaction ne correspond à vos filtres actuels.'}
              action={transactions.length === 0 ? (
                <Link to="/send" className="btn btn-cyan" style={{ fontSize: 11, textDecoration: 'none' }}>↗ Envoyer maintenant</Link>
              ) : undefined}
            />
          </div>
        </motion.div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {filteredTxs.map((tx, idx) => (
            <motion.button
              key={tx.id}
              onClick={() => setSelected(tx)}
              initial={{ opacity: 0, y: 12 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: idx * 0.04 }}
              whileHover={{ x: 4 }}
              className="card"
              style={{
                padding: '14px 16px', display: 'flex', alignItems: 'center', gap: 14,
                textAlign: 'left', cursor: 'pointer', width: '100%',
              }}
            >
              {/* Icône type — primitive .ib (carrée, cohérente avec le dashboard) */}
              <div className={`ib ${typeIb(tx.type)}`} style={{ width: 40, height: 40, borderRadius: 10, fontSize: 18, flexShrink: 0 }}>
                {typeIcon(tx.type)}
              </div>

              {/* Infos */}
              <div style={{ flex: 1, minWidth: 0 }}>
                <div className="tx-name" style={{ whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                  {tx.label}
                </div>
                <div className="tx-meta" style={{ whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                  {typeLabel(tx.type)} · {formatDate(tx.created_at)}
                  {tx.provider && ` · ${tx.provider}`}
                </div>
              </div>

              {/* Montant et statut */}
              <div style={{ textAlign: 'right', flexShrink: 0 }}>
                <div className="tx-amount" style={{ color: tx.direction === 'in' ? 'var(--green)' : 'var(--text-bright)' }}>
                  {formatAmount(tx)}
                </div>
                <span className={`pill ${statusClass(tx.status)}`} style={{ marginTop: 4, fontSize: 9 }}>
                  {statusLabel(tx.status)}
                </span>
              </div>
            </motion.button>
          ))}
        </div>
      )}

      {/* ─── Drawer de détails ─── */}
      <AnimatePresence>
        {selected && (
          <>
            <motion.div
              onClick={() => setSelected(null)}
              initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
              style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.6)', zIndex: 200, backdropFilter: 'blur(2px)' }}
            />
            <motion.div
              initial={{ x: 420, opacity: 0 }} animate={{ x: 0, opacity: 1 }} exit={{ x: 420, opacity: 0 }}
              transition={{ type: 'spring', damping: 28, stiffness: 260 }}
              style={{
                position: 'fixed', top: 0, right: 0, bottom: 0, width: 'min(440px, 92vw)',
                background: 'var(--panel)', borderLeft: '1px solid var(--border)', zIndex: 201,
                padding: 28, overflowY: 'auto', boxShadow: '-20px 0 60px rgba(0,0,0,0.5)',
              }}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 24 }}>
                <span className="page-label">Détail de la transaction</span>
                <button onClick={() => setSelected(null)} className="btn btn-ghost" style={{ fontSize: 11 }}>✕ Fermer</button>
              </div>

              {/* Icône + titre */}
              <div style={{ textAlign: 'center', marginBottom: 24 }}>
                <div className={`ib ${typeIb(selected.type)}`} style={{ width: 64, height: 64, borderRadius: 16, margin: '0 auto 14px', fontSize: 26 }}>
                  {typeIcon(selected.type)}
                </div>
                <h2 style={{ color: 'var(--text-bright)', marginBottom: 4, fontSize: 18 }}>{selected.label}</h2>
                <span className={`pill ${statusClass(selected.status)}`} style={{ fontSize: 10 }}>{statusLabel(selected.status)}</span>
              </div>

              {/* Montant principal */}
              <div style={{ textAlign: 'center', padding: '20px', background: 'rgba(0,0,0,0.3)', borderRadius: 14, marginBottom: 20 }}>
                <div className="mono" style={{ fontSize: 32, fontWeight: 800, color: selected.direction === 'in' ? 'var(--green)' : 'var(--text-bright)' }}>
                  {formatAmount(selected)}
                </div>
                <div style={{ fontSize: 11, color: 'var(--text-dim)', marginTop: 6 }}>{typeLabel(selected.type)} · {new Date(selected.created_at).toLocaleString('fr-FR')}</div>
              </div>

              {/* Détails structurés */}
              <div style={{ display: 'flex', flexDirection: 'column', gap: 0 }}>
                {[
                  ['Type', typeLabel(selected.type)],
                  ['Statut', statusLabel(selected.status).replace(/^[✓⏳⚙✕]+\s*/, '')],
                  ['Devise', `${selected.currency}`],
                  ['Provider', selected.provider || '—'],
                  ['Destination', selected.destination || '—'],
                  ['Réf. route', selected.route_id ? `route ${selected.route_id}` : '—'],
                  ['Date', new Date(selected.created_at).toLocaleString('fr-FR')],
                  selected.dest_amount != null ? ['Montant reçu', `${selected.dest_amount.toLocaleString('fr-FR')} ${selected.dest_currency || ''}`] : null,
                  selected.fx_rate != null ? ['Taux FX', selected.fx_rate.toFixed(4)] : null,
                  selected.fee != null && selected.fee > 0 ? ['Frais', `${selected.fee.toLocaleString('fr-FR')} ${selected.fee_currency || ''}`] : null,
                ].filter(Boolean).map((row, i) => row && (
                  <div key={i} style={{
                    display: 'flex', justifyContent: 'space-between', padding: '12px 0',
                    borderBottom: i < 4 ? '1px solid var(--border-soft)' : 'none', fontSize: 12,
                  }}>
                    <span style={{ color: 'var(--text-dim)' }}>{row[0]}</span>
                    <span style={{ color: 'var(--text-bright)', fontWeight: 600, textAlign: 'right' }}>{row[1]}</span>
                  </div>
                ))}
              </div>
            </motion.div>
          </>
        )}
      </AnimatePresence>
    </div>
  );
}
