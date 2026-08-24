import { useState } from 'react';
import {
  apiProviderTest,
  type SourceStatuses,
  type StaffDashboardData,
} from '../../api/client';
import { Badge, Stat, Table, fmtDate, fmtMoney } from '../admin/adminUi';

export type StaffRunAction = (payload: Record<string, unknown>, successMessage?: string) => Promise<unknown>;
export type StaffConfirm = (options: {
  title: string;
  body: string;
  confirmLabel: string;
  danger?: boolean;
  reasonRequired?: boolean;
  action: (reason: string) => void;
}) => void;

function Button({ children, onClick, disabled, tone }: {
  children: React.ReactNode;
  onClick: () => void;
  disabled?: boolean;
  tone?: 'danger' | 'success' | 'warning';
}) {
  const color = tone === 'danger' ? 'var(--red)' : tone === 'success' ? 'var(--green)' : tone === 'warning' ? 'var(--gold)' : 'var(--text-mid)';
  return (
    <button type="button" className="btn btn-ghost" onClick={onClick} disabled={disabled}
      style={{ fontSize: 11, padding: '5px 9px', color }}>
      {children}
    </button>
  );
}

function Card({ title, children }: { title: string; children: React.ReactNode }) {
  return <section className="card shine-sweep" style={{ padding: 14 }}><h3 style={{ margin: '0 0 10px', fontSize: 12 }}>{title}</h3>{children}</section>;
}

function Sources({ sources }: { sources: SourceStatuses }) {
  const fxAvailable = sources.fx.status === 'AVAILABLE' || sources.fx.valid_cache_entries > 0;
  const sanctionsAvailable = sources.sanctions.status === 'CONFIGURED';
  return (
    <Card title="Sources externes">
      <Table head={['Source', 'État', 'Détail']} rows={[
        ['FX', <Badge key="fx" status={fxAvailable ? 'operational' : 'unavailable'} label={fxAvailable ? 'Disponible' : 'Indisponible'} />,
          <span key="fd">{sources.fx.valid_cache_entries}/{sources.fx.cache_entries} taux cache valides{sources.fx.fail_closed ? ' · fail-closed' : ''}</span>],
        ['Sanctions', <Badge key="sa" status={sanctionsAvailable ? 'configured' : 'unavailable'} label={sources.sanctions.status} />,
          <span key="sd">{sources.sanctions.blocks_environment ? 'Bloque cet environnement' : 'Ne bloque pas cet environnement'}</span>],
      ]} />
    </Card>
  );
}

export function OperationsConsole({ section, query }: {
  section: NonNullable<StaffDashboardData['sections']['operations']>;
  query: string;
}) {
  const rows = section.queue.filter((row) => `${row.id} ${row.label} ${row.user_name ?? ''} ${row.status}`.toLowerCase().includes(query));
  return <>
    <div className="g4">
      <Stat label="En attente" value={section.counters.pending} tone="var(--gold)" />
      <Stat label="En traitement" value={section.counters.processing} />
      <Stat label="Terminées" value={section.counters.completed} tone="var(--green)" />
      <Stat label="Échouées" value={section.counters.failed} tone="var(--red)" />
    </div>
    <Card title={`File d'exécution · moyenne ${section.avg_execution_seconds.toFixed(1)} s`}>
      <Table head={['#', 'Opération', 'Client', 'Montant', 'Statut', 'Provider', 'Créée le']}
        rows={rows.map((row) => [
          `#${row.id}`, row.label, row.user_name ?? '—', `${fmtMoney(Number(row.amount))} ${row.currency}`,
          <Badge key="s" status={row.status} />, row.provider ?? '—', fmtDate(row.created_at),
        ])}
        empty="Aucune transaction en file. Les mutations directes sont volontairement indisponibles." />
    </Card>
  </>;
}

export function FinanceConsole({ section, runAction }: {
  section: NonNullable<StaffDashboardData['sections']['finance']>;
  runAction: StaffRunAction;
}) {
  const [pair, setPair] = useState('EUR/USD');
  const [result, setResult] = useState('');
  const check = async () => {
    const value = await runAction({ console: 'finance', action: 'fx_check', pair }, 'Sonde FX terminée.') as { message?: string } | null;
    setResult(value?.message ?? '');
  };
  return <>
    <div className="g4">
      {(['EUR', 'USD', 'XAF'] as const).map((currency) =>
        <Stat key={currency} label={`Actifs ${currency}`} value={Number(section.assets[currency]).toLocaleString('fr-FR')} sub={`${currency} · available_balance`} />)}
      <Stat label="Volume traité" value={section.transactions.volume_xaf.toLocaleString('fr-FR')} sub="XAF" />
    </div>
    <Card title="Répartition des transactions">
      <Table head={['Statut', 'Nombre']} rows={section.status_breakdown.map((row) => [<Badge key="s" status={row.status} />, row.n])} empty="Aucune transaction." />
    </Card>
    <Sources sources={section.sources} />
    {section.actions.includes('fx_check') && <Card title="Sonde FX réelle">
      <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
        <input value={pair} onChange={(e) => setPair(e.target.value)} aria-label="Paire de devises" className="staff-filter" />
        <Button onClick={() => void check()}>Vérifier</Button>
        {result && <span style={{ fontSize: 12 }}>{result}</span>}
      </div>
    </Card>}
  </>;
}

export function ComplianceConsole({ section, query, ask, runAction }: {
  section: NonNullable<StaffDashboardData['sections']['compliance']>;
  query: string;
  ask: StaffConfirm;
  runAction: StaffRunAction;
}) {
  const rows = section.pending.filter((row) => `${row.full_name} ${row.email} ${row.status}`.toLowerCase().includes(query));
  const action = (row: typeof rows[number], kind: 'kyc_approve' | 'kyc_reject' | 'kyc_resubmission') => ask({
    title: kind === 'kyc_approve' ? 'Approuver le dossier' : kind === 'kyc_reject' ? 'Rejeter le dossier' : 'Demander une resoumission',
    body: `Confirmer la décision pour ${row.full_name} (#${row.id}).`,
    confirmLabel: 'Confirmer',
    danger: kind === 'kyc_reject',
    reasonRequired: kind !== 'kyc_approve',
    action: (reason) => void runAction({ console: 'compliance', action: kind, verification_id: row.id, reason }),
  });
  return <>
    <div className="g4">
      <Stat label="Dossiers" value={section.counters.total} />
      <Stat label="Individuels" value={Object.values(section.counters.individual).reduce((a, b) => a + b, 0)} />
      <Stat label="Entreprises" value={Object.values(section.counters.company).reduce((a, b) => a + b, 0)} />
      <Stat label="À traiter" value={section.pending.length} tone="var(--gold)" />
    </div>
    <Card title="Dossiers à traiter">
      <Table head={['Client', 'Type', 'Statut', 'Date', 'Actions']} rows={rows.map((row) => [
        `${row.full_name} · ${row.email}`, row.subject_type, <Badge key="s" status={row.status} />, fmtDate(row.created_at),
        <div key="a" style={{ display: 'flex', gap: 5 }}>
          {section.actions.includes('kyc_approve') && <Button tone="success" onClick={() => action(row, 'kyc_approve')}>Approuver</Button>}
          {section.actions.includes('kyc_reject') && <Button tone="danger" onClick={() => action(row, 'kyc_reject')}>Rejeter</Button>}
          {section.actions.includes('kyc_resubmission') && <Button tone="warning" onClick={() => action(row, 'kyc_resubmission')}>Resoumission</Button>}
        </div>,
      ])} empty="Aucun dossier à traiter." />
    </Card>
    <Card title="Source sanctions">
      <Badge status={section.sanctions.status === 'CONFIGURED' ? 'configured' : 'unavailable'} label={section.sanctions.status} />
      {section.sanctions.blocks_environment && <span style={{ marginLeft: 10, color: 'var(--red)' }}>Vérification bloquante dans cet environnement.</span>}
    </Card>
  </>;
}

export function RiskConsole({ section, ask, runAction }: {
  section: NonNullable<StaffDashboardData['sections']['risk']>;
  ask: StaffConfirm;
  runAction: StaffRunAction;
}) {
  return <>
    <div className="g4">
      <Stat label="Comptes suspendus" value={section.risk.suspended_accounts} />
      <Stat label="Transactions échouées" value={section.risk.failed_transactions} tone="var(--red)" />
      <Stat label="KYC rejetés" value={section.risk.kyc_rejected} />
      <Stat label="Taux d'échec" value={`${section.risk.failed_rate}%`} />
    </div>
    <Card title="Comptes à surveiller · portée globale">
      <Table head={['Client', 'Statut', 'Risque', 'Actions']} rows={section.flagged.map((user) => [
        `${user.full_name} · ${user.email}`, <Badge key="s" status={user.status} />, user.risk_level ?? 'unknown',
        <div key="a" style={{ display: 'flex', gap: 6 }}>
          {user.status === 'SUSPENDED'
            ? <Button tone="success" onClick={() => void runAction({ console: 'risk', action: 'unsuspend', user_id: user.id })}>Réactiver</Button>
            : <Button tone="danger" onClick={() => ask({
              title: 'Suspendre le compte', body: `Suspendre ${user.full_name} ?`, confirmLabel: 'Suspendre',
              danger: true, reasonRequired: true,
              action: (reason) => void runAction({ console: 'risk', action: 'suspend', user_id: user.id, reason }),
            })}>Suspendre</Button>}
          <select aria-label={`Niveau de risque de ${user.full_name}`} value={user.risk_level ?? 'low'}
            onChange={(e) => void runAction({ console: 'risk', action: 'risk_level', user_id: user.id, level: e.target.value })}>
            <option value="low">Faible</option><option value="medium">Moyen</option><option value="high">Élevé</option>
          </select>
        </div>,
      ])} empty="Aucun compte signalé." />
    </Card>
    <Card title="Échecs récents">
      <Table head={['Opération', 'Client', 'Montant', 'Provider', 'Date']} rows={section.recent_failed.map((row) => [
        row.label, row.user_email ?? '—', `${row.amount} ${row.currency}`, row.provider ?? '—', fmtDate(row.created_at),
      ])} empty="Aucun échec récent." />
    </Card>
  </>;
}

export function ProvidersConsole({ section }: { section: NonNullable<StaffDashboardData['sections']['providers']> }) {
  const [message, setMessage] = useState('');
  const test = async (slug: string, environment: 'sandbox' | 'production') => {
    const response = await apiProviderTest(slug, environment);
    setMessage(response.success ? `${slug}: ${response.data?.result.status ?? 'unknown'}` : response.error ?? 'Test impossible.');
  };
  return <>
    <div className="g3">
      <Stat label="Catalogue" value={section.providers.total} />
      <Stat label="Activés" value={section.providers.enabled} />
      <Stat label="Configurés" value={section.providers.configured} />
    </div>
    {message && <div role="status" className="card" style={{ padding: 10 }}>{message}</div>}
    <Card title="Credentials providers">
      <Table head={['Provider', 'Environnement', 'État', 'Dernier test', 'Action']} rows={section.credentials.map((credential) => [
        credential.provider_slug, credential.environment, <Badge key="s" status={credential.state} />,
        credential.last_tested_at ? fmtDate(credential.last_tested_at) : 'Jamais',
        <Button key="a" onClick={() => void test(credential.provider_slug, credential.environment)}>Tester via /providers/{'{slug}'}/test</Button>,
      ])} empty="Aucune credential enregistrée." />
    </Card>
  </>;
}

export function SupportConsole({ section, runAction }: {
  section: NonNullable<StaffDashboardData['sections']['support']>;
  runAction: StaffRunAction;
}) {
  const [specialistByTicket, setSpecialistByTicket] = useState<Record<number, number>>({});
  const escalate = (ticketId: number) => {
    const specialistId = specialistByTicket[ticketId] ?? 0;
    const reason = window.prompt('Motif obligatoire de l’escalade :')?.trim();
    if (!specialistId || !reason) return;
    void runAction({
      console: 'support', action: 'ticket_escalate', conversation_id: ticketId,
      specialist_id: specialistId, difficulty: 'modere', reason,
    });
  };
  return <>
    <div className="g4">
      <Stat label="Ouverts" value={section.counters.open} />
      <Stat label="En attente client" value={section.counters.waiting} tone="var(--gold)" />
      <Stat label="Résolus" value={section.counters.resolved} />
      <Stat label="Clos" value={section.counters.closed} />
    </div>
    <Card title="Tickets ouverts / en attente">
      <Table head={['Sujet', 'Client', 'Priorité', 'Statut', 'Actions']} rows={section.recent.map((ticket) => [
        ticket.subject, `${ticket.full_name} · ${ticket.email}`, ticket.priority, <Badge key="s" status={ticket.status} />,
        <div key="a" style={{ display: 'flex', gap: 5 }}>
          {section.actions.includes('ticket_assign') && <Button onClick={() => void runAction({ console: 'support', action: 'ticket_assign', conversation_id: ticket.id })}>Me l'assigner</Button>}
          {section.actions.includes('ticket_escalate') && <>
            <select aria-label={`Spécialiste pour le ticket ${ticket.id}`} value={specialistByTicket[ticket.id] ?? 0}
              onChange={(event) => setSpecialistByTicket((current) => ({ ...current, [ticket.id]: Number(event.target.value) }))}>
              <option value={0}>Spécialiste…</option>
              {section.specialists.map((specialist) => <option key={specialist.id} value={specialist.id}>{specialist.full_name} · {specialist.dashboard ?? specialist.platform_role}</option>)}
            </select>
            <Button tone="warning" disabled={!specialistByTicket[ticket.id]} onClick={() => escalate(ticket.id)}>Escalader</Button>
          </>}
          {ticket.status !== 'waiting' && <Button tone="warning" onClick={() => void runAction({ console: 'support', action: 'ticket_status', conversation_id: ticket.id, status: 'waiting' })}>Attente client</Button>}
          {ticket.status !== 'resolved' && <Button tone="success" onClick={() => void runAction({ console: 'support', action: 'ticket_status', conversation_id: ticket.id, status: 'resolved' })}>Résoudre</Button>}
        </div>,
      ])} empty="Aucun ticket ouvert ou en attente." />
    </Card>
  </>;
}

export function TechnicalConsole({ section, runAction }: {
  section: NonNullable<StaffDashboardData['sections']['technical']>;
  runAction: StaffRunAction;
}) {
  const [providerNotice, setProviderNotice] = useState('');
  const serviceKey = (name: string) => name.includes('Base') ? 'database' : name.includes('File') ? 'queue' : name.includes('KYC') ? 'kyc' : 'api';
  const testProvider = async (slug: string, environment: 'sandbox' | 'production') => {
    const response = await apiProviderTest(slug, environment);
    setProviderNotice(response.success ? `${slug}: ${response.data?.result.status ?? 'unknown'}` : response.error ?? 'Test impossible.');
  };
  return <>
    <div className="g4">
      <Stat label="Services opérationnels" value={section.services.filter((s) => s.status === 'operational').length} sub={`sur ${section.services.length}`} />
      <Stat label="Webhooks providers" value={section.webhooks.provider_events} />
      <Stat label="Webhooks KYC" value={section.webhooks.kyc_events} />
      <Stat label="Base de données" value={section.db_ok ? 'OK' : 'DOWN'} tone={section.db_ok ? 'var(--green)' : 'var(--red)'} />
    </div>
    <Card title="Santé des services">
      <Table head={['Service', 'Statut', 'Latence', 'Action']} rows={section.services.map((service) => [
        service.name, <Badge key="s" status={service.status} />, service.latency_ms === null ? 'Non mesurée' : `${service.latency_ms} ms`,
        section.actions.includes('service_check')
          ? <Button key="a" onClick={() => void runAction({ console: 'technical', action: 'service_check', service: serviceKey(service.name) })}>Vérifier</Button>
          : '—',
      ])} />
    </Card>
    <Card title="Credentials providers">
      {providerNotice && <div role="status" style={{ marginBottom: 8 }}>{providerNotice}</div>}
      <Table head={['Provider', 'Environnement', 'État', 'Dernier test', 'Action']} rows={section.credentials.map((credential) => [
        credential.provider_slug, credential.environment, <Badge key="s" status={credential.state} />,
        credential.last_tested_at ? fmtDate(credential.last_tested_at) : 'Jamais',
        <Button key="a" onClick={() => void testProvider(credential.provider_slug, credential.environment)}>Tester</Button>,
      ])} empty="Aucune credential." />
    </Card>
    <Sources sources={section.sources} />
  </>;
}

export function BusinessConsole({ section, ask, runAction }: {
  section: NonNullable<StaffDashboardData['sections']['business']>;
  ask: StaffConfirm;
  runAction: StaffRunAction;
}) {
  return <>
    <div className="g4">
      <Stat label="Entreprises" value={section.accounts.total} />
      <Stat label="KYB vérifiés" value={section.accounts.verified} />
      <Stat label="Actives" value={section.accounts.active} />
      <Stat label="KYB en attente" value={section.accounts.pending} tone="var(--gold)" />
    </div>
    <Card title="Comptes business · portée globale">
      <Table head={['Entreprise', 'Statut', 'KYB', 'Volume XAF', 'Actions']} rows={section.top.map((company) => [
        `${company.full_name} · ${company.email}`, <Badge key="s" status={company.status} />,
        <Badge key="k" status={company.kyb_status ?? 'unknown'} />, Number(company.volume).toLocaleString('fr-FR'),
        company.kyb_status === 'verified' ? '—' : <div key="a" style={{ display: 'flex', gap: 5 }}>
          {section.actions.includes('kyb_approve') && <Button tone="success" onClick={() => void runAction({ console: 'business', action: 'kyb_approve', user_id: company.id })}>Valider</Button>}
          {section.actions.includes('kyb_reject') && <Button tone="danger" onClick={() => ask({
            title: 'Rejeter le KYB', body: `Rejeter le dossier de ${company.full_name} ?`, confirmLabel: 'Rejeter',
            danger: true, reasonRequired: true,
            action: (reason) => void runAction({ console: 'business', action: 'kyb_reject', user_id: company.id, reason }),
          })}>Rejeter</Button>}
        </div>,
      ])} empty="Aucun compte entreprise." />
    </Card>
  </>;
}
