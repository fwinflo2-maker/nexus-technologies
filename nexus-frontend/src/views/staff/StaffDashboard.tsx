import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiStaffDashboard, apiStaffAction, type StaffDashboardData, type StaffActionResult } from '../../api/client';
import { Stat, Badge, Table, fmtMoney, fmtDate } from '../admin/adminUi';
import TechLoader from '../../components/anim/TechLoader';

/**
 * Dashboard métier de l'employé — rendu strictement par rôle RBAC.
 *
 * Le backend (/api/control/staff/dashboard) ne renvoie que la section du rôle
 * connecté, et POST /api/control/staff/action autorise UNIQUEMENT les actions
 * de sa console (le rôle est dérivé côté serveur, jamais depuis le client).
 * Chaque action est écrite en base et tracée dans audit_logs.
 */

const fmtVol = (n: number) => n.toLocaleString('fr-FR');

function TxStatusBadge({ status }: { status: string }) {
  return <Badge status={status} label={status} />;
}

/** Petits boutons d'action compactes, style console. */
function ActionBtn({
  label, onClick, tone = 'default', disabled, title,
}: {
  label: string;
  onClick: () => void;
  tone?: 'default' | 'success' | 'danger' | 'gold';
  disabled?: boolean;
  title?: string;
}) {
  const colors: Record<string, string> = {
    default: 'rgba(255,255,255,0.06)',
    success: 'rgba(34,197,94,0.14)',
    danger: 'rgba(239,68,68,0.14)',
    gold: 'rgba(245,158,11,0.14)',
  };
  const text: Record<string, string> = {
    default: 'var(--text-mid)',
    success: 'var(--green)',
    danger: 'var(--red)',
    gold: 'var(--gold)',
  };
  return (
    <button
      onClick={onClick}
      disabled={disabled}
      title={title}
      style={{
        background: colors[tone],
        color: text[tone],
        border: '1px solid rgba(255,255,255,0.09)',
        borderRadius: 7,
        padding: '4px 9px',
        fontSize: 11.5,
        fontWeight: 600,
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.45 : 1,
        whiteSpace: 'nowrap',
        fontFamily: 'inherit',
        transition: 'transform 0.15s ease, opacity 0.15s ease',
      }}
      onMouseEnter={(e) => { if (!disabled) e.currentTarget.style.transform = 'translateY(-1px)'; }}
      onMouseLeave={(e) => { e.currentTarget.style.transform = 'none'; }}
    >
      {label}
    </button>
  );
}

/** Modale de confirmation (motif requis pour les actions sensibles). */
function ConfirmModal({
  title, body, confirmLabel, danger, reasonRequired, busy, onConfirm, onCancel,
}: {
  title: string;
  body: string;
  confirmLabel: string;
  danger?: boolean;
  reasonRequired?: boolean;
  busy: boolean;
  onConfirm: (reason: string) => void;
  onCancel: () => void;
}) {
  const [reason, setReason] = useState('');
  return (
    <div style={{
      position: 'fixed', inset: 0, zIndex: 500, background: 'rgba(5,6,12,0.72)',
      display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20, backdropFilter: 'blur(6px)',
    }} onClick={onCancel}>
      <div
        onClick={(e) => e.stopPropagation()}
        style={{
          width: '100%', maxWidth: 430, background: 'linear-gradient(160deg, rgba(20,23,38,0.98), rgba(11,13,22,0.98))',
          border: '1px solid var(--border-soft)', borderRadius: 16, padding: 22,
          boxShadow: '0 24px 70px rgba(0,0,0,0.55), 0 0 0 1px rgba(255,255,255,0.02)',
        }}
      >
        <div style={{ fontSize: 15, fontWeight: 800, color: danger ? 'var(--red)' : 'var(--text-bright)', marginBottom: 8 }}>
          {title}
        </div>
        <div style={{ fontSize: 12.5, color: 'var(--text-mid)', lineHeight: 1.6, marginBottom: 14 }}>{body}</div>
        {reasonRequired && (
          <>
            <label style={{ display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-dim)', textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 6 }}>
              Motif (obligatoire)
            </label>
            <textarea
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              rows={3}
              autoFocus
              placeholder="Expliquez la raison de cette action…"
              style={{
                width: '100%', boxSizing: 'border-box', background: 'rgba(255,255,255,0.04)',
                border: '1px solid var(--border-soft)', borderRadius: 10, color: 'var(--text)',
                padding: '10px 12px', fontSize: 12.5, fontFamily: 'inherit', resize: 'vertical',
              }}
            />
          </>
        )}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 18 }}>
          <button
            onClick={onCancel}
            style={{
              background: 'rgba(255,255,255,0.06)', color: 'var(--text-mid)', border: '1px solid var(--border-soft)',
              borderRadius: 10, padding: '9px 16px', fontSize: 12.5, fontWeight: 600, cursor: 'pointer', fontFamily: 'inherit',
            }}
          >Annuler</button>
          <button
            onClick={() => onConfirm(reason)}
            disabled={busy || (reasonRequired && reason.trim() === '')}
            style={{
              background: danger ? 'var(--red)' : 'var(--cyan)', color: '#fff', border: 'none',
              borderRadius: 10, padding: '9px 18px', fontSize: 12.5, fontWeight: 700, cursor: busy || (reasonRequired && reason.trim() === '') ? 'not-allowed' : 'pointer',
              opacity: busy || (reasonRequired && reason.trim() === '') ? 0.5 : 1, fontFamily: 'inherit',
            }}
          >
            {busy ? '…' : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}

export default function StaffDashboard() {
  const [data, setData] = useState<StaffDashboardData | null>(null);
  const [failed, setFailed] = useState(false);
  const [notice, setNotice] = useState<{ kind: 'ok' | 'err'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [confirm, setConfirm] = useState<{
    title: string; body: string; confirmLabel: string; danger?: boolean; reasonRequired?: boolean;
    run: (reason: string) => void;
  } | null>(null);
  const [escalate, setEscalate] = useState<{ id: number; subject: string } | null>(null);
  const [query, setQuery] = useState('');

  const load = useCallback(async () => {
    const res = await apiStaffDashboard();
    if (res.success && res.data) setData(res.data);
    else setFailed(true);
  }, []);

  useEffect(() => {
    let alive = true;
    void (async () => {
      const res = await apiStaffDashboard();
      if (!alive) return;
      if (res.success && res.data) setData(res.data);
      else setFailed(true);
    })();
    return () => { alive = false; };
  }, []);

  const runAction = useCallback(async (body: Parameters<typeof apiStaffAction>[0], successMsg?: string) => {
    setBusy(true);
    const res = await apiStaffAction(body);
    setBusy(false);
    if (res.success && res.data) {
      setNotice({ kind: 'ok', text: res.data.message ?? successMsg ?? 'Action effectuée.' });
      void load();
    } else {
      setNotice({ kind: 'err', text: res.error ?? 'Action refusée.' });
    }
  }, [load]);

  /** Demande une confirmation (avec motif éventuel) avant l'action. */
  const ask = (opts: {
    title: string; body: string; confirmLabel: string; danger?: boolean; reasonRequired?: boolean;
    action: (reason: string) => void;
  }) => setConfirm({ ...opts, run: opts.action });

  if (failed) {
    return (
      <div style={{ padding: 24, textAlign: 'center', color: 'var(--text-dim)', fontSize: 13 }}>
        Impossible de charger votre dashboard.
      </div>
    );
  }
  if (!data) {
    return <div style={{ display: 'flex', justifyContent: 'center', padding: 30 }}><TechLoader label="Chargement du dashboard" /></div>;
  }

  const s = data.sections;
  const q = query.trim().toLowerCase();
  const match = (...parts: Array<string | number | null | undefined>) =>
    !q || parts.some((p) => String(p ?? '').toLowerCase().includes(q));
  const opsQueue = s.operations ? s.operations.queue.filter((r) => match(r.id, r.label, r.user_name, r.status, r.provider, r.currency)) : [];
  const kycPending = s.compliance ? s.compliance.pending.filter((r) => match(r.full_name, r.email, r.status, r.subject_type)) : [];
  const tickets = s.support ? s.support.recent.filter((t) => match(t.subject, t.full_name, t.email, t.status, t.category, t.priority)) : [];
  const txActionCell = (r: { id: number; status: string }) => {
    if (r.status === 'pending') {
      return (
        <div style={{ display: 'flex', gap: 6 }}>
          <ActionBtn label="✓ Valider" tone="success" onClick={() => runAction({ console: 'operations', action: 'tx_approve', transaction_id: r.id })} />
          <ActionBtn label="✕ Annuler" tone="danger" onClick={() => ask({
            title: 'Annuler la transaction',
            body: `Annuler la transaction #${r.id} ? Elle sera marquée « cancelled ».`,
            confirmLabel: 'Annuler la transaction',
            danger: true,
            reasonRequired: true,
            action: (reason) => runAction({ console: 'operations', action: 'tx_cancel', transaction_id: r.id, reason }),
          })} />
        </div>
      );
    }
    if (r.status === 'failed') {
      return (
        <ActionBtn label="↻ Relancer" tone="gold" onClick={() => ask({
          title: 'Relancer la transaction',
          body: `Remettre la transaction #${r.id} en file d'attente (retry) ?`,
          confirmLabel: 'Relancer',
          action: () => runAction({ console: 'operations', action: 'tx_retry', transaction_id: r.id }),
        })} />
      );
    }
    return <span style={{ color: 'var(--text-faint)', fontSize: 11.5 }}>—</span>;
  };

  const kycActionCell = (r: { id: number; subject_type: string; status: string }) => (
    <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
      <ActionBtn label="✓ Approuver" tone="success" onClick={() => ask({
        title: 'Approuver le dossier KYC/KYB',
        body: `Valider le dossier #${r.id} ? Le compte sera mis à jour (kyc_level / KYB).`,
        confirmLabel: 'Approuver',
        action: () => runAction({ console: 'compliance', action: 'kyc_approve', verification_id: r.id }),
      })} />
      <ActionBtn label="✕ Rejeter" tone="danger" onClick={() => ask({
        title: 'Rejeter le dossier KYC/KYB',
        body: `Rejeter le dossier #${r.id} ? Un motif sera enregistré.`,
        confirmLabel: 'Rejeter',
        danger: true,
        reasonRequired: true,
        action: (reason) => runAction({ console: 'compliance', action: 'kyc_reject', verification_id: r.id, reason }),
      })} />
      <ActionBtn label="↻ Resoumission" tone="gold" onClick={() => ask({
        title: 'Demander une resoumission',
        body: `Demander de nouveaux documents pour le dossier #${r.id} ?`,
        confirmLabel: 'Demander',
        reasonRequired: true,
        action: (reason) => runAction({ console: 'compliance', action: 'kyc_resubmission', verification_id: r.id, reason }),
      })} />
    </div>
  );

  const specialists = s.support?.specialists ?? [];
  const specialistName = (id: number | null) => {
    if (!id) return null;
    return specialists.find((sp) => sp.id === id)?.full_name ?? null;
  };

  const ticketActionCell = (t: { id: number; subject: string; status: string; assigned_to: number | null }) => (
    <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
      <ActionBtn label="🎧 Me l'assigner" onClick={() => runAction({ console: 'support', action: 'ticket_assign', conversation_id: t.id })} />
      <ActionBtn label="🚨 Escalader" tone="gold" onClick={() => setEscalate({ id: t.id, subject: t.subject })} title="Transférer vers un employé spécialiste selon la difficulté" />
      {t.status !== 'waiting' && (
        <ActionBtn label="En attente client" tone="gold" onClick={() => runAction({ console: 'support', action: 'ticket_status', conversation_id: t.id, status: 'waiting' })} />
      )}
      {t.status !== 'resolved' && (
        <ActionBtn label="✓ Résoudre" tone="success" onClick={() => runAction({ console: 'support', action: 'ticket_status', conversation_id: t.id, status: 'resolved' })} />
      )}
      <ActionBtn label="Fermer" tone="danger" onClick={() => ask({
        title: 'Fermer le ticket',
        body: `Fermer définitivement le ticket #${t.id} ?`,
        confirmLabel: 'Fermer',
        danger: true,
        action: () => runAction({ console: 'support', action: 'ticket_status', conversation_id: t.id, status: 'closed' }),
      })} />
    </div>
  );

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      {/* Bandeau d'action + actualisation */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
        {notice ? (
          <div style={{
            flex: 1, padding: '9px 14px', borderRadius: 10, fontSize: 12.5, fontWeight: 600,
            background: notice.kind === 'ok' ? 'rgba(34,197,94,0.12)' : 'rgba(239,68,68,0.12)',
            color: notice.kind === 'ok' ? 'var(--green)' : 'var(--red)',
            border: `1px solid ${notice.kind === 'ok' ? 'rgba(34,197,94,0.25)' : 'rgba(239,68,68,0.25)'}`,
          }}>
            {notice.kind === 'ok' ? '✓ ' : '⚠ '}{notice.text}
          </div>
        ) : (
          <div style={{ flex: 1, fontSize: 11.5, color: 'var(--text-faint)' }}>
            Actions tracées dans le journal d'audit · généré {fmtDate(data.generated_at)}
          </div>
        )}
        <input
          className="staff-filter"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Filtrer files, tickets, dossiers…"
        />
        <ActionBtn label="↻ Actualiser" onClick={() => { void load(); }} title="Recharger les données" />
      </div>

      {/* ═══ OPERATIONS ═══ */}
      {s.operations && (
        <>
          <div className="g4">
            <Stat label="En attente" value={s.operations.counters.pending} tone="var(--gold)" index={0} />
            <Stat label="En traitement" value={s.operations.counters.processing} tone="var(--cyan2)" index={1} />
            <Stat label="Terminées" value={s.operations.counters.completed} tone="var(--green)" index={2} />
            <Stat label="Échouées" value={s.operations.counters.failed} tone="var(--red)" index={3} />
          </div>
          <div className="card shine-sweep" style={{ padding: 14 }}>
            <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>
              ⚙️ FILE D'EXÉCUTION ({opsQueue.length}) · exécution moyenne {s.operations.avg_execution_seconds.toFixed(1)}s
            </div>
            <Table
              head={['#', 'Opération', 'Client', 'Montant', 'Devise', 'Statut', 'Provider', 'Créée le', 'Action']}
              rows={opsQueue.map((r) => [
                <span key="id" style={{ fontFamily: 'var(--font-mono)', color: 'var(--text-dim)' }}>#{r.id}</span>,
                <span key="l" style={{ color: 'var(--text-bright)' }}>{r.label}</span>,
                <span key="u" style={{ color: 'var(--text-mid)' }}>{r.user_name ?? '—'}</span>,
                <span key="a" style={{ fontFamily: 'var(--font-mono)' }}>{fmtMoney(Number(r.amount))}</span>,
                <span key="c" style={{ fontFamily: 'var(--font-mono)', color: 'var(--cyan2)' }}>{r.currency}</span>,
                <TxStatusBadge key="st" status={r.status} />,
                <span key="p" style={{ fontFamily: 'var(--font-mono)', color: 'var(--text-mid)' }}>{r.provider ?? '—'}</span>,
                <span key="d" style={{ fontSize: 11.5, color: 'var(--text-dim)' }}>{fmtDate(r.created_at)}</span>,
                txActionCell(r),
              ])}
              empty="Aucune transaction en file d'exécution."
            />
          </div>
        </>
      )}

      {/* ═══ FINANCE / TRÉSORERIE ═══ */}
      {s.finance && (
        <>
          <div className="g4">
            <Stat label="Actifs EUR" value={fmtVol(Number(s.finance.assets.EUR))} sub="EUR" tone="var(--cyan2)" index={0} />
            <Stat label="Actifs USD" value={fmtVol(Number(s.finance.assets.USD))} sub="USD" index={1} />
            <Stat label="Actifs XAF" value={fmtVol(Number(s.finance.assets.XAF))} sub="XAF" index={2} />
            <Stat label="Volume traité" value={fmtVol(s.finance.transactions.volume_xaf)} sub="FCFA · transactions terminées" tone="var(--green)" index={3} />
          </div>
          <div className="card shine-sweep" style={{ padding: 14 }}>
            <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>📊 RÉPARTITION DES TRANSACTIONS</div>
            <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
              {s.finance.status_breakdown.map((st) => (
                <div key={st.status} style={{ flex: 1, minWidth: 130, padding: '12px 14px', borderRadius: 10, border: '1px solid var(--border-soft)', background: 'rgba(255,255,255,0.02)' }}>
                  <div style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-dim)', textTransform: 'uppercase', letterSpacing: 0.4 }}>{st.status}</div>
                  <div style={{ fontSize: 20, fontWeight: 800, color: 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>{st.n}</div>
                </div>
              ))}
              <div style={{ flex: 1, minWidth: 130, padding: '12px 14px', borderRadius: 10, border: '1px solid var(--border-soft)', background: 'rgba(255,255,255,0.02)' }}>
                <div style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-dim)', textTransform: 'uppercase', letterSpacing: 0.4 }}>Total</div>
                <div style={{ fontSize: 20, fontWeight: 800, color: 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>{s.finance.transactions.total}</div>
              </div>
            </div>
          </div>
          <FxCheck />
        </>
      )}

      {/* ═══ COMPLIANCE ═══ */}
      {s.compliance && (
        <>
          <div className="g4">
            <Stat label="Dossiers KYC/KYB" value={s.compliance.counters.total ?? 0} index={0} />
            <Stat label="Individuels" value={Object.values(s.compliance.counters.individual ?? {}).reduce((a, b) => a + b, 0)} index={1} />
            <Stat label="Entreprises" value={Object.values(s.compliance.counters.company ?? {}).reduce((a, b) => a + b, 0)} index={2} />
            <Stat label="En attente / à vérifier" value={s.compliance.pending.length} tone="var(--gold)" index={3} />
          </div>
          <div className="card shine-sweep" style={{ padding: 14 }}>
            <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>🛡️ DOSSIERS À TRAITER</div>
            <Table
              head={['Client', 'Type', 'Statut', 'Soumis le', 'Action']}
              rows={kycPending.map((r) => [
                <span key="c" style={{ color: 'var(--text-bright)' }}>{r.full_name ?? '—'} <span style={{ color: 'var(--text-dim)', fontFamily: 'var(--font-mono)', fontSize: 11 }}>{r.email ?? ''}</span></span>,
                <span key="t" style={{ color: 'var(--text-mid)' }}>{r.subject_type === 'company' ? 'Entreprise (KYB)' : 'Individuel (KYC)'}</span>,
                <Badge key="st" status={r.status} />,
                <span key="d" style={{ fontSize: 11.5, color: 'var(--text-dim)' }}>{fmtDate(r.created_at)}</span>,
                kycActionCell(r),
              ])}
              empty="Aucun dossier en attente — file de conformité vide."
            />
          </div>
        </>
      )}

      {/* ═══ RISQUE / FRAUDE ═══ */}
      {s.risk && (
        <>
          <div className="g4">
            <Stat label="Comptes suspendus" value={s.risk.risk.suspended_accounts} tone="var(--gold)" index={0} />
            <Stat label="Transactions échouées" value={s.risk.risk.failed_transactions} tone="var(--red)" index={1} />
            <Stat label="KYC rejetés" value={s.risk.risk.kyc_rejected} tone="var(--red)" index={2} />
            <Stat label="Taux d'échec" value={`${s.risk.risk.failed_rate}%`} index={3} />
          </div>
          <div className="card shine-sweep" style={{ padding: 14 }}>
            <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>🚨 COMPTES À SURVEILLER ({s.risk.flagged.length})</div>
            <Table
              head={['Client', 'Email', 'Statut', 'Risque', 'Action']}
              rows={s.risk.flagged.map((u) => [
                <span key="n" style={{ color: 'var(--text-bright)' }}>{u.full_name}</span>,
                <span key="e" style={{ color: 'var(--text-mid)', fontFamily: 'var(--font-mono)', fontSize: 11.5 }}>{u.email}</span>,
                <Badge key="st" status={u.status} />,
                <span key="r" style={{ fontFamily: 'var(--font-mono)', color: u.risk_level === 'high' ? 'var(--red)' : u.risk_level === 'medium' ? 'var(--gold)' : 'var(--text-mid)' }}>{u.risk_level ?? '—'}</span>,
                <div key="a" style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                  {u.status !== 'SUSPENDED' ? (
                    <ActionBtn label="⛔ Suspendre" tone="danger" onClick={() => ask({
                      title: 'Suspendre le compte',
                      body: `Suspendre le compte de ${u.full_name} (#${u.id}) ? L'authentification et les opérations seront bloquées.`,
                      confirmLabel: 'Suspendre',
                      danger: true,
                      reasonRequired: true,
                      action: (reason) => runAction({ console: 'risk', action: 'suspend', user_id: u.id, reason }),
                    })} />
                  ) : (
                    <ActionBtn label="✓ Réactiver" tone="success" onClick={() => ask({
                      title: 'Réactiver le compte',
                      body: `Réactiver le compte de ${u.full_name} (#${u.id}) ?`,
                      confirmLabel: 'Réactiver',
                      action: () => runAction({ console: 'risk', action: 'unsuspend', user_id: u.id }),
                    })} />
                  )}
                  <RiskLevelSelect value={u.risk_level ?? 'low'} id={u.id} onChange={(level) => runAction({ console: 'risk', action: 'risk_level', user_id: u.id, level })} />
                </div>,
              ])}
              empty="Aucun compte à surveiller."
            />
          </div>
          <div className="card shine-sweep" style={{ padding: 14 }}>
            <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>🚨 ÉCHECS RÉCENTS À SURVEILLER</div>
            <Table
              head={['Opération', 'Client', 'Montant', 'Devise', 'Provider', 'Date']}
              rows={s.risk.recent_failed.map((r) => [
                <span key="l" style={{ color: 'var(--text-bright)' }}>{r.label}</span>,
                <span key="u" style={{ color: 'var(--text-mid)', fontFamily: 'var(--font-mono)', fontSize: 11.5 }}>{r.user_email ?? '—'}</span>,
                <span key="a" style={{ fontFamily: 'var(--font-mono)' }}>{fmtMoney(Number(r.amount))}</span>,
                <span key="c" style={{ fontFamily: 'var(--font-mono)', color: 'var(--cyan2)' }}>{r.currency}</span>,
                <span key="p" style={{ fontFamily: 'var(--font-mono)', color: 'var(--text-mid)' }}>{r.provider ?? '—'}</span>,
                <span key="d" style={{ fontSize: 11.5, color: 'var(--text-dim)' }}>{fmtDate(r.created_at)}</span>,
              ])}
              empty="Aucun échec récent."
            />
          </div>
          <div className="card shine-sweep" style={{ padding: 14 }}>
            <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>📊 TAUX D'ÉCHEC PAR PROVIDER</div>
            <Table
              head={['Provider', 'Transactions', 'Échecs', 'Taux']}
              rows={s.risk.by_provider.map((p) => [
                <span key="p" style={{ fontFamily: 'var(--font-mono)', color: 'var(--cyan2)' }}>{p.provider}</span>,
                <span key="n" style={{ fontFamily: 'var(--font-mono)' }}>{p.n}</span>,
                <span key="f" style={{ fontFamily: 'var(--font-mono)', color: p.fails > 0 ? 'var(--red)' : 'var(--green)' }}>{p.fails}</span>,
                <span key="r" style={{ fontFamily: 'var(--font-mono)', color: p.fail_rate > 10 ? 'var(--red)' : p.fail_rate > 0 ? 'var(--gold)' : 'var(--green)' }}>{p.fail_rate}%</span>,
              ])}
              empty="Aucune donnée provider."
            />
          </div>
        </>
      )}

      {/* ═══ PROVIDERS ═══ */}
      {s.providers && (
        <>
          <div className="g4">
            <Stat label="Providers au catalogue" value={s.providers.providers.total} index={0} />
            <Stat label="Activés" value={s.providers.providers.enabled} tone="var(--green)" index={1} />
            <Stat label="Configurés" value={s.providers.providers.configured} tone="var(--cyan2)" index={2} />
          </div>
          <div className="card shine-sweep" style={{ padding: 14 }}>
            <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>🔌 ÉTAT DES CREDENTIALS</div>
            <Table
              head={['Provider', 'Environnement', 'État', 'Dernier test', 'Action']}
              rows={s.providers.credentials.map((c) => [
                <span key="s" style={{ fontFamily: 'var(--font-mono)', color: 'var(--cyan2)' }}>{c.provider_slug}</span>,
                <span key="e" style={{ fontFamily: 'var(--font-mono)', color: 'var(--text-mid)' }}>{c.environment}</span>,
                <Badge key="st" status={c.state} />,
                <span key="t" style={{ fontSize: 11.5, color: 'var(--text-dim)' }}>{c.last_tested_at ? fmtDate(c.last_tested_at) : 'Jamais testé'}</span>,
                <ActionBtn
                  key="a"
                  label="⚡ Tester"
                  tone={c.state === 'error' ? 'gold' : 'default'}
                  onClick={() => runAction({ console: 'providers', action: 'credential_test', provider_slug: c.provider_slug, environment: c.environment })}
                  title={`Test de connexion réel ${c.provider_slug} (${c.environment})`}
                />,
              ])}
              empty="Aucune credential enregistrée."
            />
          </div>
        </>
      )}

      {/* ═══ SUPPORT ═══ */}
      {s.support && (
        <>
          <div className="g4">
            <Stat label="Ouverts" value={s.support.counters.open} tone="var(--gold)" index={0} />
            <Stat label="En attente client" value={s.support.counters.waiting} tone="var(--cyan2)" index={1} />
            <Stat label="Résolus" value={s.support.counters.resolved} tone="var(--green)" index={2} />
            <Stat label="Clos" value={s.support.counters.closed} index={3} />
          </div>
          <div className="card shine-sweep" style={{ padding: 14 }}>
            <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>🎧 TICKETS OUVERTS / EN ATTENTE</div>
            <Table
              head={['Sujet', 'Client', 'Catégorie', 'Priorité', 'Statut', 'Créé le', 'Action']}
              rows={tickets.map((t) => [
                <span key="s" style={{ color: 'var(--text-bright)' }}>{t.subject}{specialistName(t.assigned_to) && <span style={{ display: 'block', fontSize: 10.5, color: 'var(--gold)', fontWeight: 600 }}>→ {specialistName(t.assigned_to)}</span>}</span>,
                <span key="c" style={{ color: 'var(--text-mid)' }}>{t.full_name ?? '—'} <span style={{ color: 'var(--text-dim)', fontFamily: 'var(--font-mono)', fontSize: 11 }}>{t.email ?? ''}</span></span>,
                <span key="g" style={{ color: 'var(--text-mid)' }}>{t.category ?? '—'}</span>,
                <span key="p" style={{ fontFamily: 'var(--font-mono)', color: t.priority === 'urgent' ? 'var(--red)' : t.priority === 'high' ? 'var(--gold)' : 'var(--text-mid)' }}>{t.priority}</span>,
                <Badge key="st" status={t.status} />,
                <span key="d" style={{ fontSize: 11.5, color: 'var(--text-dim)' }}>{fmtDate(t.created_at)}</span>,
                ticketActionCell(t),
              ])}
              empty="Aucun ticket ouvert."
            />
          </div>
        </>
      )}

      {/* ═══ TECHNIQUE ═══ */}
      {s.technical && (
        <>
          <div className="g4">
            <Stat label="Services" value={s.technical.services.filter((sv) => sv.status === 'operational').length} sub={`sur ${s.technical.services.length} opérationnels`} tone="var(--green)" index={0} />
            <Stat label="Webhooks traités" value={s.technical.webhooks.processed_total} index={1} />
            <Stat label="Credentials" value={s.technical.credentials.length} index={2} />
            <Stat label="Base de données" value={s.technical.db_ok ? 'OK' : 'DOWN'} tone={s.technical.db_ok ? 'var(--green)' : 'var(--red)'} index={3} />
          </div>
          <div className="card shine-sweep" style={{ padding: 14 }}>
            <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>🖥️ SANTÉ DES SERVICES</div>
            <Table
              head={['Service', 'Statut', 'Action']}
              rows={s.technical.services.map((sv) => [
                <span key="n" style={{ color: 'var(--text-bright)' }}>{sv.name}</span>,
                <Badge key="st" status={sv.status} />,
                <ActionBtn
                  key="a"
                  label="⚡ Vérifier"
                  onClick={() => runAction({ console: 'technical', action: 'service_check', service: sv.name === 'Base de données' ? 'database' : sv.name === 'File de transactions' ? 'queue' : sv.name === 'KYC (SumSub)' ? 'kyc' : 'api' })}
                  title="Son de contrôle en direct"
                />,
              ])}
            />
          </div>
          <div className="card shine-sweep" style={{ padding: 14 }}>
            <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>🔌 CREDENTIALS PROVIDERS</div>
            <Table
              head={['Provider', 'Environnement', 'État', 'Action']}
              rows={s.technical.credentials.map((c) => [
                <span key="s" style={{ fontFamily: 'var(--font-mono)', color: 'var(--cyan2)' }}>{c.provider_slug}</span>,
                <span key="e" style={{ fontFamily: 'var(--font-mono)', color: 'var(--text-mid)' }}>{c.environment}</span>,
                <Badge key="st" status={c.state} />,
                <ActionBtn
                  key="a"
                  label="⚡ Tester"
                  tone={c.state === 'error' ? 'gold' : 'default'}
                  onClick={() => runAction({ console: 'technical', action: 'credential_test', provider_slug: c.provider_slug, environment: c.environment })}
                  title={`Test de connexion réel ${c.provider_slug} (${c.environment})`}
                />,
              ])}
              empty="Aucune credential."
            />
          </div>
        </>
      )}

      {/* ═══ BUSINESS ═══ */}
      {s.business && (
        <>
          <div className="g4">
            <Stat label="Comptes entreprise" value={s.business.accounts.total} index={0} />
            <Stat label="KYB vérifiés" value={s.business.accounts.verified} tone="var(--green)" index={1} />
            <Stat label="Actifs" value={s.business.accounts.active} tone="var(--cyan2)" index={2} />
            <Stat label="Volume business" value={fmtVol(s.business.volume_xaf)} sub="FCFA" tone="var(--gold)" index={3} />
          </div>
          <div className="card shine-sweep" style={{ padding: 14 }}>
            <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>🏢 TOP ENTREPRISES</div>
            <Table
              head={['Entreprise', 'Contact', 'KYB', 'Volume (FCFA)', 'Action']}
              rows={s.business.top.map((b) => [
                <span key="n" style={{ color: 'var(--text-bright)' }}>{b.full_name}</span>,
                <span key="e" style={{ color: 'var(--text-mid)', fontFamily: 'var(--font-mono)', fontSize: 11.5 }}>{b.email}</span>,
                <Badge key="k" status={b.kyb_status} />,
                <span key="v" style={{ fontFamily: 'var(--font-mono)', color: 'var(--cyan2)' }}>{fmtVol(b.volume)}</span>,
                <div key="a" style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                  {b.kyb_status !== 'verified' && (
                    <>
                      <ActionBtn label="✓ Valider KYB" tone="success" onClick={() => ask({
                        title: 'Valider le KYB',
                        body: `Valider la vérification d'entreprise de ${b.full_name} ?`,
                        confirmLabel: 'Valider',
                        action: () => runAction({ console: 'business', action: 'kyb_approve', user_id: b.id }),
                      })} />
                      <ActionBtn label="✕ Rejeter" tone="danger" onClick={() => ask({
                        title: 'Rejeter le KYB',
                        body: `Rejeter le KYB de ${b.full_name} ? Un motif sera enregistré.`,
                        confirmLabel: 'Rejeter',
                        danger: true,
                        reasonRequired: true,
                        action: (reason) => runAction({ console: 'business', action: 'kyb_reject', user_id: b.id, reason }),
                      })} />
                    </>
                  )}
                </div>,
              ])}
              empty="Aucun compte entreprise."
            />
          </div>
        </>
      )}

      {/* ═══ EXECUTIVE (super admin) ═══ */}
      {s.executive && (
        <div className="staff-exec">
          <div>
            <div className="staff-topbar-kicker">Console direction</div>
            <p>{s.executive.note || 'Vue consolidée de la plateforme. Les leviers opérationnels restent dans la console Super Admin.'}</p>
          </div>
          <Link to="/admin" className="btn btn-primary btn-sm" style={{ textDecoration: 'none' }}>Ouvrir Super Admin</Link>
        </div>
      )}

      {/* Modale d'escalade vers un spécialiste */}
      {escalate && (
        <EscalateModal
          ticket={escalate}
          specialists={specialists}
          busy={busy}
          onConfirm={(specialistId, difficulty, reason) => {
            setEscalate(null);
            void runAction({
              console: 'support',
              action: 'ticket_escalate',
              conversation_id: escalate.id,
              specialist_id: specialistId,
              difficulty,
              reason,
            });
          }}
          onCancel={() => setEscalate(null)}
        />
      )}

      {/* Modale de confirmation */}
      {confirm && (
        <ConfirmModal
          title={confirm.title}
          body={confirm.body}
          confirmLabel={confirm.confirmLabel}
          danger={confirm.danger}
          reasonRequired={confirm.reasonRequired}
          busy={busy}
          onConfirm={(reason) => {
            setConfirm(null);
            confirm.run(reason);
          }}
          onCancel={() => setConfirm(null)}
        />
      )}
    </div>
  );
}

/** Sélecteur de niveau de risque d'un compte (console RISK). */
function RiskLevelSelect({ value, id, onChange }: { value: string; id: number; onChange: (level: string) => void }) {
  return (
    <select
      value={value}
      onChange={(e) => onChange(e.target.value)}
      title={`Niveau de risque du compte #${id}`}
      style={{
        background: 'rgba(255,255,255,0.06)', color: 'var(--text-mid)', border: '1px solid rgba(255,255,255,0.09)',
        borderRadius: 7, padding: '4px 8px', fontSize: 11.5, fontWeight: 600, cursor: 'pointer', fontFamily: 'inherit',
      }}
    >
      <option value="low">Risque faible</option>
      <option value="medium">Risque moyen</option>
      <option value="high">Risque élevé</option>
    </select>
  );
}

/** Modale d'escalade support : difficulté + spécialiste + motif. */
function EscalateModal({ ticket, specialists, busy, onConfirm, onCancel }: {
  ticket: { id: number; subject: string };
  specialists: Array<{ id: number; full_name: string; platform_role: string; department: string | null; dashboard: string | null }>;
  busy: boolean;
  onConfirm: (specialistId: number, difficulty: string, reason: string) => void;
  onCancel: () => void;
}) {
  const [difficulty, setDifficulty] = useState('modere');
  const [specialistId, setSpecialistId] = useState<number>(0);
  const [reason, setReason] = useState('');

  const difficultyHelp: Record<string, string> = {
    simple: 'Question simple — réponse directe du support.',
    modere: 'Besoin d\'un avis spécialisé — vérification d\'un collègue.',
    complexe: 'Problème technique ou réglementaire — expert requis.',
    critique: 'Incident critique — escalade immédiate vers la direction / sécurité.',
  };

  const valid = specialistId > 0 && reason.trim() !== '';

  return (
    <div style={{
      position: 'fixed', inset: 0, zIndex: 510, background: 'rgba(5,6,12,0.72)', display: 'flex', alignItems: 'center', justifyContent: 'center',
      padding: 20, backdropFilter: 'blur(6px)',
    }} onClick={onCancel}>
      <div
        onClick={(e) => e.stopPropagation()}
        style={{
          width: '100%', maxWidth: 460, background: 'linear-gradient(160deg, rgba(20,23,38,0.98), rgba(11,13,22,0.98))',
          border: '1px solid var(--border-soft)', borderRadius: 16, padding: 22, boxShadow: '0 24px 70px rgba(0,0,0,0.55)',
        }}
      >
        <div style={{ fontSize: 15, fontWeight: 800, color: 'var(--gold)', marginBottom: 4 }}>🚨 Escalade du ticket #{ticket.id}</div>
        <div style={{ fontSize: 12, color: 'var(--text-mid)', marginBottom: 14 }}>{ticket.subject}</div>

        {/* Difficulté */}
        <label style={{ display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-dim)', textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 6 }}>Difficulté</label>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 7, marginBottom: 12 }}>
          {(['simple', 'modere', 'complexe', 'critique'] as const).map((d) => (
            <button
              key={d}
              onClick={() => setDifficulty(d)}
              style={{
                padding: '9px 10px', borderRadius: 10, cursor: 'pointer', fontFamily: 'inherit', textAlign: 'left',
                fontSize: 12, fontWeight: 700, color: difficulty === d ? '#04121c' : 'var(--text-mid)',
                background: difficulty === d ? 'var(--gold)' : 'rgba(255,255,255,0.04)',
                border: `1px solid ${difficulty === d ? 'var(--gold)' : 'var(--border-soft)'}`,
              }}
            >
              {d === 'simple' ? '🟢 Simple' : d === 'modere' ? '🟡 Modéré' : d === 'complexe' ? '🟠 Complexe' : '🔴 Critique'}
              <span style={{ display: 'block', fontSize: 10, fontWeight: 500, opacity: 0.8, marginTop: 2 }}>{difficultyHelp[d]}</span>
            </button>
          ))}
        </div>

        {/* Spécialiste */}
        <label style={{ display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-dim)', textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 6 }}>
          Employé spécialiste
        </label>
        <select
          value={specialistId}
          onChange={(e) => setSpecialistId(Number(e.target.value))}
          style={{
            width: '100%', background: 'rgba(255,255,255,0.04)', border: '1px solid var(--border-soft)', borderRadius: 10,
            color: 'var(--text)', padding: '9px 12px', fontSize: 12.5, fontFamily: 'inherit', marginBottom: 12,
          }}
        >
          <option value={0}>— Choisir un spécialiste —</option>
          {specialists.map((sp) => (
            <option key={sp.id} value={sp.id}>
              {sp.full_name} · {sp.platform_role}{sp.department ? ` · ${sp.department}` : ''}
            </option>
          ))}
        </select>

        {/* Motif */}
        <label style={{ display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-dim)', textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 6 }}>Motif (obligatoire)</label>
        <textarea
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          rows={3}
          placeholder="Pourquoi ce ticket dépasse-t-il le support ? Ce motif sera transmis au spécialiste."
          style={{
            width: '100%', boxSizing: 'border-box', background: 'rgba(255,255,255,0.04)', border: '1px solid var(--border-soft)',
            borderRadius: 10, color: 'var(--text)', padding: '10px 12px', fontSize: 12.5, fontFamily: 'inherit', resize: 'vertical',
          }}
        />

        <div style={{ fontSize: 11, color: 'var(--text-dim)', marginTop: 10 }}>
          Une discussion interne sera ouverte avec le spécialiste (fichier d'échange lié au ticket), et la priorité sera ajustée selon la difficulté.
        </div>

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 16 }}>
          <button
            onClick={onCancel}
            style={{ background: 'rgba(255,255,255,0.06)', color: 'var(--text-mid)', border: '1px solid var(--border-soft)', borderRadius: 10, padding: '9px 16px', fontSize: 12.5, fontWeight: 600, cursor: 'pointer', fontFamily: 'inherit' }}
          >Annuler</button>
          <button
            onClick={() => onConfirm(specialistId, difficulty, reason.trim())}
            disabled={busy || !valid}
            style={{ background: 'var(--gold)', color: '#04121c', border: 'none', borderRadius: 10, padding: '9px 18px', fontSize: 12.5, fontWeight: 700, cursor: busy || !valid ? 'not-allowed' : 'pointer', opacity: busy || !valid ? 0.5 : 1, fontFamily: 'inherit' }}
          >{busy ? '…' : 'Escalader le ticket'}</button>
        </div>
      </div>
    </div>
  );
}

/** Sonde de taux de change réel (console FINANCE). */
function FxCheck() {
  const [pair, setPair] = useState('EUR/USD');
  const [result, setResult] = useState<StaffActionResult | null>(null);
  const [err, setErr] = useState('');
  const [loading, setLoading] = useState(false);

  async function check() {
    setLoading(true);
    setErr('');
    setResult(null);
    const res = await apiStaffAction({ console: 'finance', action: 'fx_check', pair: pair.trim() });
    setLoading(false);
    if (res.success && res.data) setResult(res.data);
    else setErr(res.error ?? 'Son de taux impossible.');
  }

  return (
    <div className="card shine-sweep" style={{ padding: 14 }}>
      <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>💱 SONDE DE TAUX DE CHANGE</div>
      <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
        <input
          value={pair}
          onChange={(e) => setPair(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') void check(); }}
          placeholder="EUR/USD"
          style={{
            width: 140, background: 'rgba(255,255,255,0.04)', border: '1px solid var(--border-soft)', borderRadius: 9,
            color: 'var(--text)', padding: '8px 12px', fontSize: 12.5, fontFamily: 'var(--font-mono)', outline: 'none',
          }}
        />
        <ActionBtn label={loading ? '…' : 'Vérifier le taux'} onClick={() => void check()} disabled={loading} />
        {result && (
          <div style={{ fontSize: 12.5, fontWeight: 600, color: result.rate !== null && result.rate !== undefined ? 'var(--green)' : 'var(--gold)' }}>
            {result.message}
          </div>
        )}
        {err && <div style={{ fontSize: 12.5, color: 'var(--red)', fontWeight: 600 }}>⚠ {err}</div>}
      </div>
    </div>
  );
}
