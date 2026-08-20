import { useCallback, useEffect, useState } from 'react';
import { apiControlKyc, apiControlKycOverride } from '../../api/client';
import { Badge, Stat, Table, fmtDate } from './adminUi';

interface Applicant {
  id: number; user_id: number; full_name: string; email: string; provider: string;
  environment: string; subject_type: string; applicant_id: string; level_name: string;
  status: string; reason: string | null; reviewed_at: string | null; created_at: string;
}

type Decision = 'approve' | 'reject' | 'resubmission';

export default function AdminCompliance() {
  const [counters, setCounters] = useState<Record<string, unknown>>({});
  const [applicants, setApplicants] = useState<Applicant[]>([]);
  const [canOverride, setCanOverride] = useState(false);
  const [busyId, setBusyId] = useState<number | 'new' | null>(null);
  const [notice, setNotice] = useState<{ kind: 'ok' | 'err'; text: string } | null>(null);
  const [prompt, setPrompt] = useState<{
    decision: Decision;
    verificationId?: number;
    userId?: number;
    subjectType?: 'individual' | 'company';
    label: string;
  } | null>(null);
  const [reason, setReason] = useState('');
  const [manualUserId, setManualUserId] = useState('');
  const [manualSubject, setManualSubject] = useState<'individual' | 'company'>('individual');

  const load = useCallback(async () => {
    const res = await apiControlKyc();
    if (res.success && res.data) {
      setCounters(res.data.counters as unknown as Record<string, unknown>);
      setApplicants(res.data.applicants as unknown as Applicant[]);
      setCanOverride(Boolean(res.data.can_manual_override));
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  const runOverride = async () => {
    if (!prompt) return;
    const trimmed = reason.trim();
    if (trimmed.length < 8) {
      setNotice({ kind: 'err', text: 'Motif obligatoire (min. 8 caractères) — documentez pourquoi Sumsub ne suffit pas.' });
      return;
    }
    setBusyId(prompt.verificationId ?? 'new');
    setNotice(null);
    const res = await apiControlKycOverride({
      decision: prompt.decision,
      reason: trimmed,
      verification_id: prompt.verificationId,
      user_id: prompt.userId,
      subject_type: prompt.subjectType,
    });
    setBusyId(null);
    if (!res.success) {
      setNotice({ kind: 'err', text: res.error ?? 'Override impossible.' });
      return;
    }
    setPrompt(null);
    setReason('');
    setNotice({
      kind: 'ok',
      text: prompt.decision === 'approve'
        ? `Dossier #${res.data?.verification_id} validé manuellement (secours Sumsub).`
        : prompt.decision === 'reject'
          ? `Dossier #${res.data?.verification_id} rejeté.`
          : `Resoumission demandée sur #${res.data?.verification_id}.`,
    });
    await load();
  };

  const openForApplicant = (a: Applicant, decision: Decision) => {
    setReason('');
    setPrompt({
      decision,
      verificationId: a.id,
      label: `${a.full_name} · dossier #${a.id}`,
    });
  };

  const openManualCreate = (decision: Decision) => {
    const uid = Number(manualUserId);
    if (!Number.isFinite(uid) || uid <= 0) {
      setNotice({ kind: 'err', text: 'Indiquez un user_id client valide.' });
      return;
    }
    setReason('');
    setPrompt({
      decision,
      userId: uid,
      subjectType: manualSubject,
      label: `user #${uid} · ${manualSubject === 'company' ? 'KYB' : 'KYC'} (sans dossier Sumsub)`,
    });
  };

  const c = counters as unknown as { total?: number; pending?: number; verified?: number };
  const decisionLabel = prompt?.decision === 'approve' ? 'Valider' : prompt?.decision === 'reject' ? 'Rejeter' : 'Demander resoumission';

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
      <div className="g4">
        <Stat label="Dossiers KYC" value={c.total ?? applicants.length} />
        <Stat label="En attente" value={c.pending ?? 0} tone="var(--gold)" />
        <Stat label="Vérifiés" value={c.verified ?? 0} tone="var(--green)" />
        <Stat label="Provider" value="SumSub" sub={counters.provider ? String((counters.provider as { slug?: string }).slug ?? '') : ''} />
      </div>

      {notice && (
        <div style={{
          padding: '10px 14px', borderRadius: 10, fontSize: 12.5, fontWeight: 600,
          background: notice.kind === 'ok' ? 'rgba(34,197,94,0.12)' : 'rgba(239,68,68,0.12)',
          color: notice.kind === 'ok' ? 'var(--green)' : 'var(--red)',
        }}>{notice.text}</div>
      )}

      {canOverride && (
        <div className="card" style={{ padding: 14, border: '1px solid rgba(245,158,11,0.35)' }}>
          <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 6 }}>
            Override manuel exclusif Super Admin
          </div>
          <div style={{ fontSize: 12, color: 'var(--text-mid)', marginBottom: 12, lineHeight: 1.45 }}>
            Secours si Sumsub est indisponible ou incohérent. Chaque décision exige un motif et est journalisée (`kyc.approve` / `kyc.reject`). Aucun autre rôle ne peut utiliser ce chemin.
          </div>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, alignItems: 'center' }}>
            <input
              type="number"
              min={1}
              placeholder="user_id client"
              value={manualUserId}
              onChange={(e) => setManualUserId(e.target.value)}
              style={{ width: 140, padding: '8px 10px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--panel2)', color: 'var(--text-bright)', fontSize: 13 }}
            />
            <select
              value={manualSubject}
              onChange={(e) => setManualSubject(e.target.value as 'individual' | 'company')}
              style={{ padding: '8px 10px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--panel2)', color: 'var(--text-bright)', fontSize: 13 }}
            >
              <option value="individual">KYC (personne)</option>
              <option value="company">KYB (entreprise)</option>
            </select>
            <button type="button" onClick={() => openManualCreate('approve')}
              style={{ padding: '8px 12px', borderRadius: 8, border: '1px solid rgba(34,197,94,0.4)', background: 'rgba(34,197,94,0.12)', color: 'var(--green)', fontWeight: 700, cursor: 'pointer', fontSize: 12 }}>
              ✓ Valider sans dossier
            </button>
            <button type="button" onClick={() => openManualCreate('reject')}
              style={{ padding: '8px 12px', borderRadius: 8, border: '1px solid rgba(239,68,68,0.4)', background: 'rgba(239,68,68,0.12)', color: 'var(--red)', fontWeight: 700, cursor: 'pointer', fontSize: 12 }}>
              ✕ Rejeter sans dossier
            </button>
          </div>
        </div>
      )}

      <div className="card" style={{ padding: 14 }}>
        <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>🛡️ Dossiers de vérification d'identité</div>
        <Table
          head={canOverride
            ? ['ID', 'Client', 'Type', 'Provider', 'Statut', 'Motif / remarque', 'Reçu le', 'Override']
            : ['ID', 'Client', 'Type', 'Niveau', 'Statut', 'Motif / remarque', 'Reçu le']}
          rows={applicants.map((a) => {
            const base = [
              <span style={{ fontFamily: 'var(--font-mono)', color: 'var(--text-dim)' }}>#{a.id}</span>,
              <span>
                <div style={{ color: 'var(--text-bright)', fontWeight: 600 }}>{a.full_name}</div>
                <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>{a.email} · user #{a.user_id}</div>
              </span>,
              <span style={{ textTransform: 'capitalize' }}>{a.subject_type}</span>,
              canOverride
                ? <span style={{ fontSize: 11, textTransform: 'uppercase' }}>{a.provider}</span>
                : <span style={{ textTransform: 'uppercase', fontSize: 11 }}>{a.level_name ?? '—'}</span>,
              <Badge status={a.status} />,
              <span style={{ fontSize: 12 }}>{a.reason ?? '—'}</span>,
              <span style={{ fontSize: 11.5, color: 'var(--text-mid)' }}>{fmtDate(a.created_at)}</span>,
            ];
            if (!canOverride) return base;
            return [
              ...base,
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                <button type="button" disabled={busyId === a.id}
                  onClick={() => openForApplicant(a, 'approve')}
                  style={{ fontSize: 11, padding: '4px 8px', borderRadius: 6, cursor: 'pointer', border: '1px solid rgba(34,197,94,0.35)', background: 'rgba(34,197,94,0.1)', color: 'var(--green)', fontWeight: 700 }}>
                  Valider
                </button>
                <button type="button" disabled={busyId === a.id}
                  onClick={() => openForApplicant(a, 'reject')}
                  style={{ fontSize: 11, padding: '4px 8px', borderRadius: 6, cursor: 'pointer', border: '1px solid rgba(239,68,68,0.35)', background: 'rgba(239,68,68,0.1)', color: 'var(--red)', fontWeight: 700 }}>
                  Rejeter
                </button>
                <button type="button" disabled={busyId === a.id}
                  onClick={() => openForApplicant(a, 'resubmission')}
                  style={{ fontSize: 11, padding: '4px 8px', borderRadius: 6, cursor: 'pointer', border: '1px solid rgba(245,158,11,0.35)', background: 'rgba(245,158,11,0.1)', color: 'var(--gold)', fontWeight: 700 }}>
                  Resoumettre
                </button>
              </div>,
            ];
          })}
        />
      </div>

      {prompt && (
        <div style={{
          position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.55)', zIndex: 80,
          display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16,
        }}>
          <div className="card" style={{ width: 'min(480px, 100%)', padding: 18, display: 'flex', flexDirection: 'column', gap: 12 }}>
            <div style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-bright)' }}>
              {decisionLabel} — override Sumsub
            </div>
            <div style={{ fontSize: 12.5, color: 'var(--text-mid)' }}>{prompt.label}</div>
            <label style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-dim)', textTransform: 'uppercase' }}>
              Motif (obligatoire)
            </label>
            <textarea
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              rows={4}
              placeholder="Ex. : Sumsub 5xx depuis 14h — pièces contrôlées manuellement (CNI + selfie)."
              style={{ width: '100%', padding: 10, borderRadius: 8, border: '1px solid var(--border)', background: 'var(--panel2)', color: 'var(--text-bright)', fontSize: 13, resize: 'vertical' }}
            />
            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
              <button type="button" onClick={() => { setPrompt(null); setReason(''); }}
                style={{ padding: '8px 12px', borderRadius: 8, border: '1px solid var(--border)', background: 'transparent', color: 'var(--text-mid)', cursor: 'pointer' }}>
                Annuler
              </button>
              <button type="button" onClick={() => void runOverride()} disabled={busyId !== null}
                style={{
                  padding: '8px 14px', borderRadius: 8, cursor: 'pointer', fontWeight: 700,
                  border: '1px solid transparent',
                  background: prompt.decision === 'reject' ? 'rgba(239,68,68,0.2)' : 'rgba(34,197,94,0.2)',
                  color: prompt.decision === 'reject' ? 'var(--red)' : 'var(--green)',
                }}>
                Confirmer
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
