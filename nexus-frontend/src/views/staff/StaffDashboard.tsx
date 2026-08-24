import { useEffect, useId, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import TechLoader from '../../components/anim/TechLoader';
import { fmtDate } from '../admin/adminUi';
import {
  BusinessConsole,
  ComplianceConsole,
  FinanceConsole,
  OperationsConsole,
  ProvidersConsole,
  RiskConsole,
  SupportConsole,
  TechnicalConsole,
  type StaffConfirm,
} from './StaffConsoleSections';
import { useStaffDashboard } from './useStaffDashboard';

interface Confirmation {
  title: string;
  body: string;
  confirmLabel: string;
  danger?: boolean;
  reasonRequired?: boolean;
  action: (reason: string) => void;
}

function ConfirmDialog({ confirmation, busy, onClose }: {
  confirmation: Confirmation;
  busy: boolean;
  onClose: () => void;
}) {
  const [reason, setReason] = useState('');
  const titleId = useId();
  const dialogRef = useRef<HTMLDivElement>(null);
  const cancelRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null;
    cancelRef.current?.focus();
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && !busy) onClose();
      if (event.key === 'Tab' && dialogRef.current) {
        const focusable = Array.from(dialogRef.current.querySelectorAll<HTMLElement>('button:not([disabled]), textarea, input, select'));
        if (focusable.length === 0) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
      }
    };
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      previous?.focus();
    };
  }, [busy, onClose]);

  return (
    <div style={{ position: 'fixed', inset: 0, zIndex: 500, background: 'rgba(5,6,12,.75)', display: 'grid', placeItems: 'center', padding: 20 }}
      onMouseDown={(event) => { if (event.currentTarget === event.target && !busy) onClose(); }}>
      <div ref={dialogRef} role="dialog" aria-modal="true" aria-labelledby={titleId}
        style={{ width: 'min(430px, 100%)', background: 'var(--panel)', border: '1px solid var(--border)', borderRadius: 16, padding: 22 }}>
        <h2 id={titleId} style={{ margin: '0 0 8px', fontSize: 16, color: confirmation.danger ? 'var(--red)' : 'var(--text-bright)' }}>{confirmation.title}</h2>
        <p style={{ fontSize: 12.5, color: 'var(--text-mid)' }}>{confirmation.body}</p>
        {confirmation.reasonRequired && <>
          <label htmlFor={`${titleId}-reason`} style={{ display: 'block', fontSize: 11, marginBottom: 6 }}>Motif obligatoire</label>
          <textarea id={`${titleId}-reason`} value={reason} onChange={(event) => setReason(event.target.value)} rows={3}
            style={{ width: '100%', boxSizing: 'border-box', background: 'var(--panel2)', color: 'var(--text)', border: '1px solid var(--border)', borderRadius: 8, padding: 10 }} />
        </>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button ref={cancelRef} type="button" className="btn btn-ghost" onClick={onClose} disabled={busy}>Annuler</button>
          <button type="button" className="btn" disabled={busy || (confirmation.reasonRequired && !reason.trim())}
            onClick={() => { onClose(); confirmation.action(reason.trim()); }}>{busy ? 'Traitement…' : confirmation.confirmLabel}</button>
        </div>
      </div>
    </div>
  );
}

export default function StaffDashboard() {
  const { data, state, busy, notice, load, runAction } = useStaffDashboard();
  const [query, setQuery] = useState('');
  const [confirmation, setConfirmation] = useState<Confirmation | null>(null);
  const ask: StaffConfirm = (options) => setConfirmation(options);

  if (state === 'loading' && !data) {
    return <div style={{ display: 'flex', justifyContent: 'center', padding: 30 }}><TechLoader label="Chargement du dashboard" /></div>;
  }
  if (state === 'error' || !data) {
    return <div className="card" style={{ padding: 30, textAlign: 'center' }}>
      <p>Impossible de charger votre dashboard.</p>
      <button type="button" className="btn btn-ghost" onClick={() => void load()}>Réessayer</button>
    </div>;
  }

  const sections = data.sections;
  const normalizedQuery = query.trim().toLowerCase();

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
        <div style={{ flex: 1, fontSize: 11.5, color: 'var(--text-dim)' }}>
          {data.environment} · généré {fmtDate(data.generated_at)}
        </div>
        <input className="staff-filter" value={query} onChange={(event) => setQuery(event.target.value)}
          placeholder="Filtrer la console…" aria-label="Filtrer les données de la console" />
        <button type="button" className="btn btn-ghost" onClick={() => void load()}>↻ Actualiser</button>
      </div>
      {notice && <div role="status" className="card" style={{ padding: 10, color: notice.kind === 'ok' ? 'var(--green)' : 'var(--red)' }}>{notice.text}</div>}

      {sections.operations && <OperationsConsole section={sections.operations} query={normalizedQuery} />}
      {sections.finance && <FinanceConsole section={sections.finance} runAction={runAction} />}
      {sections.compliance && <ComplianceConsole section={sections.compliance} query={normalizedQuery} ask={ask} runAction={runAction} />}
      {sections.risk && <RiskConsole section={sections.risk} ask={ask} runAction={runAction} />}
      {sections.providers && <ProvidersConsole section={sections.providers} />}
      {sections.support && <SupportConsole section={sections.support} runAction={runAction} />}
      {sections.technical && <TechnicalConsole section={sections.technical} runAction={runAction} />}
      {sections.business && <BusinessConsole section={sections.business} ask={ask} runAction={runAction} />}
      {sections.executive && <div className="staff-exec"><p>{sections.executive.note}</p><Link to="/admin" className="btn">Ouvrir Super Admin</Link></div>}

      {confirmation && <ConfirmDialog confirmation={confirmation} busy={busy} onClose={() => setConfirmation(null)} />}
    </div>
  );
}
