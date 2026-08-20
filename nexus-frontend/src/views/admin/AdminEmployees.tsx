import { useCallback, useEffect, useMemo, useState } from 'react';
import { motion } from 'framer-motion';
import {
  apiControlEmployees, apiControlCreateEmployee, apiControlUpdateEmployee,
  apiControlSetEmployeeStatus, apiControlInviteEmployee,
  type EmployeeRow, type EmployeeInvite,
} from '../../api/client';
import { Stat, Badge } from './adminUi';

/**
 * Gestion des employés internes — Super Admin.
 *
 * Création des comptes employés de Nexus Technologies selon les standards des
 * grandes fintech : rôle plateforme (platform_role) et département,
 * statut invité/actif/désactivé. Aucun mot de passe ne transite par l'admin :
 * l'employé est invité via un lien d'activation (jeton 30 min, usage unique).
 */

export const ROLE_CATALOG: Array<{ value: string; label: string; dept: string; desc: string }> = [
  { value: 'operations_manager', label: 'Operations Manager', dept: 'Operations', desc: 'Pilotage des opérations de paiement' },
  { value: 'treasury_manager', label: 'Treasury Manager', dept: 'Finance & Trésorerie', desc: 'Liquidités, trésorerie et règlements' },
  { value: 'compliance_officer', label: 'Compliance Officer', dept: 'Compliance', desc: 'KYC/KYB et conformité réglementaire' },
  { value: 'risk_analyst', label: 'Risk Analyst', dept: 'Risque & Fraude', desc: 'Analyse de risque et prévention de la fraude' },
  { value: 'provider_manager', label: 'Provider Manager', dept: 'Providers', desc: 'Corridors de paiement et partenaires' },
  { value: 'customer_support', label: 'Customer Support', dept: 'Support Client', desc: 'Assistance client de premier niveau' },
  { value: 'security_admin', label: 'Security Admin', dept: 'Sécurité', desc: 'Administration de la sécurité de la plateforme' },
  { value: 'technical_admin', label: 'Technical Admin', dept: 'Technique & Ingénierie', desc: 'Administration technique et infrastructure' },
  { value: 'business_manager', label: 'Business Manager', dept: 'Business Development', desc: 'Partenariats et développement commercial' },
  { value: 'superadmin', label: 'Super Admin', dept: 'Direction', desc: 'Privilège total sur la plateforme' },
];

const DEPARTMENTS = Array.from(new Set(ROLE_CATALOG.map((r) => r.dept)));
const ROLE_MAP = Object.fromEntries(ROLE_CATALOG.map((r) => [r.value, r]));

const STATUS_LABEL: Record<EmployeeRow['status'], string> = {
  active: 'Actif',
  invited: 'Invité',
  disabled: 'Désactivé',
};

function roleBadge(role: string) {
  const meta = ROLE_MAP[role];
  return meta ? `${meta.label}` : role;
}

const inputStyle: React.CSSProperties = {
  width: '100%', padding: '10px 12px', borderRadius: 8, border: '1px solid var(--border)',
  background: 'var(--panel2)', color: 'var(--text-bright)', fontSize: 13, fontFamily: 'inherit',
};

const labelStyle: React.CSSProperties = {
  fontSize: 11, fontWeight: 700, color: 'var(--text-dim)', textTransform: 'uppercase',
  letterSpacing: 0.4, marginBottom: 6, display: 'block',
};

export default function AdminEmployees() {
  const [rows, setRows] = useState<EmployeeRow[]>([]);
  const [state, setState] = useState<'loading' | 'error' | 'ready'>('loading');
  const [query, setQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState<'all' | EmployeeRow['status']>('all');
  const [createOpen, setCreateOpen] = useState(false);
  const [edit, setEdit] = useState<EmployeeRow | null>(null);
  const [invite, setInvite] = useState<{ row: EmployeeRow; data: EmployeeInvite } | null>(null);
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState('');
  const [notice, setNotice] = useState('');
  const [copied, setCopied] = useState(false);

  // ── Formulaire de création ────────────────────────────────────────────────
  const [form, setForm] = useState({ full_name: '', email: '', department: 'Operations', role: 'operations_manager' });

  const load = useCallback(async () => {
    setState('loading');
    const res = await apiControlEmployees();
    if (!res.success || !res.data) {
      setState('error');
      return;
    }
    setRows(res.data.items);
    setState('ready');
  }, []);

  useEffect(() => { void load(); }, [load]);

  const stats = useMemo(() => ({
    total: rows.length,
    active: rows.filter((r) => r.status === 'active').length,
    invited: rows.filter((r) => r.status === 'invited').length,
    disabled: rows.filter((r) => r.status === 'disabled').length,
  }), [rows]);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    return rows.filter((r) =>
      (statusFilter === 'all' || r.status === statusFilter) &&
      (q === '' || r.full_name.toLowerCase().includes(q) || r.email.toLowerCase().includes(q) ||
        (r.department ?? '').toLowerCase().includes(q) || r.role.toLowerCase().includes(q))
    );
  }, [rows, query, statusFilter]);

  const showNotice = (msg: string) => {
    setNotice(msg);
    window.setTimeout(() => setNotice(''), 3500);
  };

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    setFormError('');
    if (!form.full_name.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) {
      setFormError('Nom complet et adresse email valide requis.');
      return;
    }
    setBusy(true);
    try {
      const res = await apiControlCreateEmployee({
        full_name: form.full_name.trim(),
        email: form.email.trim().toLowerCase(),
        role: form.role,
        department: form.department,
      });
      if (!res.success || !res.data) {
        setFormError(res.error ?? 'Création impossible.');
        return;
      }
      setCreateOpen(false);
      setForm({ full_name: '', email: '', department: 'Operations', role: 'operations_manager' });
      // Recharge la liste puis invite immédiatement : l'employé doit pouvoir
      // définir son mot de passe via le lien d'activation.
      const list = await apiControlEmployees();
      if (list.success && list.data) setRows(list.data.items);
      showNotice(`Compte de ${form.full_name.trim()} créé. Lien d'invitation généré.`);
      const emp = list.success && list.data ? list.data.items.find((r) => r.id === res.data!.id) : undefined;
      if (emp) {
        const inv = await apiControlInviteEmployee(emp.id);
        if (inv.success && inv.data) setInvite({ row: emp, data: inv.data });
      }
    } catch {
      setFormError('Service temporairement indisponible. Veuillez réessayer.');
    } finally {
      setBusy(false);
    }
  }

  async function handleEdit(e: React.FormEvent) {
    e.preventDefault();
    if (!edit) return;
    setFormError('');
    setBusy(true);
    try {
      const res = await apiControlUpdateEmployee(edit.id, { role: edit.role, department: edit.department || undefined });
      if (!res.success) {
        setFormError(res.error ?? 'Mise à jour impossible.');
        return;
      }
      setEdit(null);
      await load();
      showNotice('Rôle / département mis à jour.');
    } catch {
      setFormError('Service temporairement indisponible. Veuillez réessayer.');
    } finally {
      setBusy(false);
    }
  }

  async function handleStatus(row: EmployeeRow, status: 'active' | 'disabled') {
    setBusy(true);
    try {
      const res = await apiControlSetEmployeeStatus(row.id, status);
      if (!res.success) {
        showNotice(res.error ?? 'Action impossible.');
        return;
      }
      await load();
      showNotice(status === 'active' ? `${row.full_name} est maintenant actif.` : `${row.full_name} a été désactivé.`);
    } catch {
      showNotice('Service temporairement indisponible.');
    } finally {
      setBusy(false);
    }
  }

  async function handleInvite(row: EmployeeRow) {
    setBusy(true);
    try {
      const res = await apiControlInviteEmployee(row.id);
      if (!res.success || !res.data) {
        showNotice(res.error ?? 'Invitation impossible.');
        return;
      }
      setInvite({ row, data: res.data });
    } catch {
      showNotice('Service temporairement indisponible.');
    } finally {
      setBusy(false);
    }
  }

  async function copyInvite() {
    if (!invite) return;
    const link = invite.data.reset_url ? `${window.location.origin}${invite.data.reset_url}` : invite.data.reset_url;
    const text = link ?? invite.data.reset_token ?? '';
    try {
      await navigator.clipboard.writeText(text);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      showNotice('Copie impossible — sélectionnez le lien manuellement.');
    }
  }

  if (state === 'loading') {
    return <div style={{ padding: 40, textAlign: 'center', color: 'var(--text-dim)', fontSize: 13 }}>Chargement des employés…</div>;
  }
  if (state === 'error') {
    return (
      <div style={{ padding: 40, textAlign: 'center' }}>
        <div style={{ fontSize: 13, color: 'var(--text-dim)', marginBottom: 12 }}>Impossible de charger les employés.</div>
        <button className="btn btn-ghost" style={{ fontSize: 12 }} onClick={() => void load()}>↻ Réessayer</button>
      </div>
    );
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      {/* Notification */}
      {notice && (
        <motion.div
          initial={{ opacity: 0, y: -8 }}
          animate={{ opacity: 1, y: 0 }}
          style={{ padding: '10px 14px', borderRadius: 10, border: '1px solid rgba(34,197,94,0.35)', background: 'rgba(34,197,94,0.08)', color: 'var(--green)', fontSize: 12.5 }}
        >
          ✓ {notice}
        </motion.div>
      )}

      {/* Statistiques */}
      <div className="g4">
        <Stat label="Total employés" value={stats.total} index={0} />
        <Stat label="Actifs" value={stats.active} tone="var(--green)" index={1} />
        <Stat label="Invités (en attente)" value={stats.invited} tone="var(--gold)" index={2} />
        <Stat label="Désactivés" value={stats.disabled} tone="var(--red)" index={3} />
      </div>

      {/* Barre d'outils */}
      <div className="card shine-sweep" style={{ padding: 14, display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
        <input
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Rechercher (nom, email, département, rôle)…"
          style={{ flex: 1, minWidth: 200, padding: '9px 12px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--panel2)', color: 'var(--text-bright)', fontSize: 12.5 }}
        />
        <select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value as typeof statusFilter)}
          style={{ padding: '9px 12px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--panel2)', color: 'var(--text-bright)', fontSize: 12.5 }}
        >
          <option value="all">Tous les statuts</option>
          <option value="active">Actifs</option>
          <option value="invited">Invités</option>
          <option value="disabled">Désactivés</option>
        </select>
        <button className="btn" style={{ fontSize: 12.5 }} onClick={() => { setFormError(''); setCreateOpen(true); }}>
          ＋ Créer un employé
        </button>
      </div>

      {/* Liste */}
      <div className="card shine-sweep" style={{ padding: 14 }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 }}>
          <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', letterSpacing: 0.3 }}>
            🧑‍💼 ÉQUIPE INTERNE ({filtered.length})
          </span>
          <button className="btn btn-ghost" style={{ fontSize: 11 }} onClick={() => void load()} disabled={busy}>↻ Actualiser</button>
        </div>

        {filtered.length === 0 ? (
          <div style={{ padding: 28, textAlign: 'center', fontSize: 12.5, color: 'var(--text-dim)' }}>
            Aucun employé {query || statusFilter !== 'all' ? 'correspondant aux filtres' : 'pour le moment — créez le premier compte employé.'}
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
              <thead>
                <tr>
                  {['Employé', 'Rôle', 'Département', 'Statut', 'Dernière connexion', 'Actions'].map((h) => (
                    <th key={h} style={{ textAlign: 'left', padding: '8px 10px', color: 'var(--text-dim)', fontWeight: 600, fontSize: 11, textTransform: 'uppercase', letterSpacing: 0.4, borderBottom: '1px solid var(--border-soft)' }}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {filtered.map((r) => {
                  const meta = ROLE_MAP[r.role];
                  return (
                    <motion.tr
                      key={r.id}
                      initial={{ opacity: 0, y: 8 }}
                      animate={{ opacity: 1, y: 0 }}
                      style={{ borderBottom: '1px solid var(--border-soft)' }}
                    >
                      <td style={{ padding: '10px' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                          <div style={{ width: 32, height: 32, borderRadius: 9, background: 'linear-gradient(135deg,#2563EB,#3B82F6)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff', fontWeight: 800, fontSize: 13, flexShrink: 0 }}>
                            {r.full_name.trim().charAt(0).toUpperCase()}
                          </div>
                          <div>
                            <div style={{ fontWeight: 600, color: 'var(--text-bright)', fontSize: 13 }}>{r.full_name}</div>
                            <div style={{ fontSize: 11, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>{r.email}</div>
                          </div>
                        </div>
                      </td>
                      <td style={{ padding: '10px', fontSize: 12 }}>
                        <span style={{ color: 'var(--cyan2)' }}>{roleBadge(r.role)}</span>
                        {meta && <div style={{ fontSize: 10.5, color: 'var(--text-dim)', marginTop: 2 }}>{meta.desc}</div>}
                      </td>
                      <td style={{ padding: '10px', fontSize: 12, color: 'var(--text-mid)' }}>{r.department ?? '—'}</td>
                      <td style={{ padding: '10px' }}>
                        <Badge status={r.status === 'invited' ? 'pending' : r.status === 'disabled' ? 'suspended' : r.status} label={STATUS_LABEL[r.status]} />
                      </td>
                      <td style={{ padding: '10px', fontSize: 11.5, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>
                        {r.last_login_at ? new Date(r.last_login_at).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'Jamais'}
                      </td>
                      <td style={{ padding: '10px' }}>
                        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                          <button className="btn btn-ghost" style={{ fontSize: 11, padding: '5px 10px' }} onClick={() => void handleInvite(r)} disabled={busy}>
                            🔗 Inviter
                          </button>
                          {r.status === 'active' ? (
                            <button className="btn btn-ghost" style={{ fontSize: 11, padding: '5px 10px', color: 'var(--gold)', borderColor: 'rgba(245,158,11,0.4)' }} onClick={() => void handleStatus(r, 'disabled')} disabled={busy}>
                              Désactiver
                            </button>
                          ) : (
                            <button className="btn btn-ghost" style={{ fontSize: 11, padding: '5px 10px', color: 'var(--green)', borderColor: 'rgba(34,197,94,0.4)' }} onClick={() => void handleStatus(r, 'active')} disabled={busy}>
                              Activer
                            </button>
                          )}
                          <button className="btn btn-ghost" style={{ fontSize: 11, padding: '5px 10px' }} onClick={() => { setFormError(''); setEdit(r); }} disabled={busy}>
                            Modifier
                          </button>
                        </div>
                      </td>
                    </motion.tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* ── Modale de création ── */}
      {createOpen && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.6)', zIndex: 400, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} onClick={() => setCreateOpen(false)}>
          <div onClick={(e) => e.stopPropagation()} style={{ width: 'min(480px, 96vw)', background: 'var(--panel)', border: '1px solid var(--border)', borderRadius: 14, padding: 22, boxShadow: '0 30px 80px rgba(0,0,0,0.6)', maxHeight: '92vh', overflowY: 'auto' }}>
            <h3 style={{ margin: '0 0 4px', fontSize: 16, color: 'var(--text-bright)' }}>🧑‍💼 Créer un compte employé</h3>
            <div style={{ fontSize: 12, color: 'var(--text-dim)', marginBottom: 16 }}>
              Compte interne Nexus Technologies. L'employé recevra ensuite un lien d'invitation pour définir son mot de passe — aucun mot de passe ne transite ici.
            </div>
            <form onSubmit={(e) => void handleCreate(e)} style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              <div>
                <label style={labelStyle}>Nom complet</label>
                <input value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} placeholder="Ex : Amina Diallo" style={inputStyle} autoFocus />
              </div>
              <div>
                <label style={labelStyle}>Email professionnel</label>
                <input value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} placeholder="prenom.nom@nexus-tech.io" style={inputStyle} />
              </div>
              <div>
                <label style={labelStyle}>Département</label>
                <select value={form.department} onChange={(e) => setForm({ ...form, department: e.target.value })} style={inputStyle}>
                  {DEPARTMENTS.map((d) => <option key={d} value={d}>{d}</option>)}
                </select>
              </div>
              <div>
                <label style={labelStyle}>Rôle plateforme</label>
                <select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })} style={inputStyle}>
                  {ROLE_CATALOG.map((r) => <option key={r.value} value={r.value}>{r.label} — {r.dept}</option>)}
                </select>
                <div style={{ fontSize: 11, color: 'var(--text-dim)', marginTop: 6 }}>
                  {ROLE_MAP[form.role]?.desc} Ce rôle détermine l'accès aux dashboards internes (RBAC).
                </div>
              </div>
              {formError && <div style={{ fontSize: 12, color: '#fca5a5' }}>⚠ {formError}</div>}
              <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', marginTop: 6 }}>
                <button type="button" className="btn btn-ghost" style={{ fontSize: 12 }} onClick={() => setCreateOpen(false)} disabled={busy}>Annuler</button>
                <button type="submit" className="btn" style={{ fontSize: 12 }} disabled={busy}>{busy ? 'Création…' : 'Créer le compte'}</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ── Modale d'invitation ── */}
      {invite && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.6)', zIndex: 500, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} onClick={() => setInvite(null)}>
          <div onClick={(e) => e.stopPropagation()} style={{ width: 'min(520px, 96vw)', background: 'var(--panel)', border: '1px solid rgba(59,130,246,0.4)', borderRadius: 14, padding: 22, boxShadow: '0 30px 80px rgba(0,0,0,0.6)' }}>
            <h3 style={{ margin: '0 0 4px', fontSize: 16, color: 'var(--text-bright)' }}>🔗 Invitation — {invite.row.full_name}</h3>
            <div style={{ fontSize: 12, color: 'var(--text-dim)', marginBottom: 14 }}>
              {invite.row.email} · Lien valable <b style={{ color: 'var(--gold)' }}>30 minutes</b>, usage unique. Transmettez-le à l'employé par un canal sûr.
            </div>
            {invite.data.reset_url ? (
              <div style={{ padding: '10px 12px', borderRadius: 8, border: '1px dashed rgba(59,130,246,0.5)', background: 'var(--panel2)', fontSize: 11.5, fontFamily: 'var(--font-mono)', color: 'var(--cyan2)', wordBreak: 'break-all', marginBottom: 12 }}>
                {window.location.origin}{invite.data.reset_url}
              </div>
            ) : (
              <div style={{ fontSize: 12, color: 'var(--text-dim)', marginBottom: 12 }}>
                L'invitation a été déclenchée (en production, le lien partirait par e-mail).
              </div>
            )}
            {invite.data.reset_token && (
              <div style={{ fontSize: 11, color: 'var(--text-dim)', marginBottom: 12, fontFamily: 'var(--font-mono)' }}>
                Jeton (dev) : <span style={{ color: 'var(--text-bright)' }}>{invite.data.reset_token}</span>
              </div>
            )}
            <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
              <button className="btn btn-ghost" style={{ fontSize: 12 }} onClick={() => setInvite(null)}>Fermer</button>
              <button className="btn" style={{ fontSize: 12 }} onClick={() => void copyInvite()}>
                {copied ? '✓ Copié' : 'Copier le lien'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ── Modale d'édition ── */}
      {edit && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.6)', zIndex: 400, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} onClick={() => setEdit(null)}>
          <div onClick={(e) => e.stopPropagation()} style={{ width: 'min(440px, 96vw)', background: 'var(--panel)', border: '1px solid var(--border)', borderRadius: 14, padding: 22, boxShadow: '0 30px 80px rgba(0,0,0,0.6)' }}>
            <h3 style={{ margin: '0 0 4px', fontSize: 16, color: 'var(--text-bright)' }}>✏️ Modifier — {edit.full_name}</h3>
            <div style={{ fontSize: 12, color: 'var(--text-dim)', marginBottom: 14 }}>{edit.email}</div>
            <form onSubmit={(e) => void handleEdit(e)} style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              <div>
                <label style={labelStyle}>Département</label>
                <select value={edit.department ?? ''} onChange={(e) => setEdit({ ...edit, department: e.target.value })} style={inputStyle}>
                  <option value="">—</option>
                  {DEPARTMENTS.map((d) => <option key={d} value={d}>{d}</option>)}
                </select>
              </div>
              <div>
                <label style={labelStyle}>Rôle plateforme</label>
                <select value={edit.role} onChange={(e) => setEdit({ ...edit, role: e.target.value })} style={inputStyle}>
                  {ROLE_CATALOG.map((r) => <option key={r.value} value={r.value}>{r.label} — {r.dept}</option>)}
                </select>
                <div style={{ fontSize: 11, color: 'var(--text-dim)', marginTop: 6 }}>{ROLE_MAP[edit.role]?.desc}</div>
              </div>
              {formError && <div style={{ fontSize: 12, color: '#fca5a5' }}>⚠ {formError}</div>}
              <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', marginTop: 6 }}>
                <button type="button" className="btn btn-ghost" style={{ fontSize: 12 }} onClick={() => setEdit(null)} disabled={busy}>Annuler</button>
                <button type="submit" className="btn" style={{ fontSize: 12 }} disabled={busy}>{busy ? 'Enregistrement…' : 'Enregistrer'}</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
