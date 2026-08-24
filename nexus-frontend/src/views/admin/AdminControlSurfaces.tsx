import { useCallback, useEffect, useState } from 'react';
import {
  apiControlLinkedClients,
  apiControlRecoverPayments,
  apiControlStuckPayments,
  apiControlWebhooks,
  type ControlWebhookEvent,
  type LinkedClientGroup,
  type StuckPayment,
} from '../../api/client';
import { Badge, Table, fmtDate } from './adminUi';

type LoadState = 'loading' | 'error' | 'ready';

function SectionState({ state, onRetry, children }: { state: LoadState; onRetry: () => void; children: React.ReactNode }) {
  if (state === 'loading') return <div className="card" style={{ padding: 28, textAlign: 'center' }}><div className="nexus-spinner" /></div>;
  if (state === 'error') return <div className="card" style={{ padding: 28, textAlign: 'center' }}>Chargement impossible. <button className="btn btn-ghost" onClick={onRetry}>Réessayer</button></div>;
  return <>{children}</>;
}

export function AdminMaintenance() {
  const [state, setState] = useState<LoadState>('loading');
  const [items, setItems] = useState<StuckPayment[]>([]);
  const [environment, setEnvironment] = useState('');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');

  const load = useCallback(async () => {
    setState('loading');
    const response = await apiControlStuckPayments();
    if (!response.success || !response.data) { setState('error'); return; }
    setItems(response.data.stuck_payments);
    setEnvironment(response.data.environment);
    setState('ready');
  }, []);
  useEffect(() => { void load(); }, [load]);

  const recover = async () => {
    if (!window.confirm(`Confirmer la réconciliation des paiements immobiles dans ${environment} ?`)) return;
    setBusy(true);
    const response = await apiControlRecoverPayments();
    setBusy(false);
    setNotice(response.success && response.data ? response.data.note : response.error ?? 'Reprise impossible.');
    if (response.success) await load();
  };

  return <SectionState state={state} onRetry={() => void load()}>
    <div className="card" style={{ padding: 14 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, marginBottom: 10 }}>
        <span>Environnement : <strong>{environment}</strong> · diagnostic en lecture seule</span>
        <button className="btn" disabled={busy || items.length === 0} onClick={() => void recover()}>{busy ? 'Réconciliation…' : 'Réconcilier les paiements récupérables'}</button>
      </div>
      {notice && <div role="status" style={{ marginBottom: 10 }}>{notice}</div>}
      <Table head={['Paiement', 'Montant', 'Immobilisé', 'Saga', 'Recommandation']} rows={items.map((item) => [
        `#${item.payment_id}`, `${item.amount} ${item.currency}`, `${Math.floor(item.stuck_seconds / 60)} min`,
        <Badge key="s" status={item.saga_status} />, item.recommendation,
      ])} empty="Aucun paiement bloqué dans cet environnement." />
    </div>
  </SectionState>;
}

export function AdminWebhooks() {
  const [state, setState] = useState<LoadState>('loading');
  const [items, setItems] = useState<ControlWebhookEvent[]>([]);
  const [environment, setEnvironment] = useState('');
  const load = useCallback(async () => {
    setState('loading');
    const response = await apiControlWebhooks();
    if (!response.success || !response.data) { setState('error'); return; }
    setItems(response.data.items);
    setEnvironment(response.data.environment);
    setState('ready');
  }, []);
  useEffect(() => { void load(); }, [load]);
  return <SectionState state={state} onRetry={() => void load()}>
    <div className="card" style={{ padding: 14 }}>
      <div style={{ marginBottom: 10 }}>Journal normalisé · {environment}</div>
      <Table head={['Source', 'Provider', 'Type événement', 'ID événement', 'Statut', 'Traité le']} rows={items.map((event) => [
        <Badge key="source" status={event.source_type} label={event.source_type} />, event.provider,
        event.event_type ?? 'Non fourni (KYC)', event.event_id, <Badge key="status" status={event.status} />, fmtDate(event.processed_at),
      ])} empty="Aucun webhook dans cet environnement." />
    </div>
  </SectionState>;
}

export function AdminLinkedAccounts() {
  const [state, setState] = useState<LoadState>('loading');
  const [groups, setGroups] = useState<LinkedClientGroup[]>([]);
  const load = useCallback(async () => {
    setState('loading');
    const response = await apiControlLinkedClients();
    if (!response.success || !response.data) { setState('error'); return; }
    setGroups(response.data.groups);
    setState('ready');
  }, []);
  useEffect(() => { void load(); }, [load]);
  return <SectionState state={state} onRetry={() => void load()}>
    <div className="card" style={{ padding: 14 }}>
      <Table head={['Signal', 'Valeur masquée', 'Risque', 'Comptes']} rows={groups.map((group) => [
        group.signal, group.detail, <Badge key="r" status={group.risk} />,
        group.members.map((member) => `${member.full_name} (#${member.id}, ${member.status})`).join(' · '),
      ])} empty="Aucun signal de comptes liés." />
    </div>
  </SectionState>;
}
