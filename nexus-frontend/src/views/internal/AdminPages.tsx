import { useCallback, useEffect, useState } from 'react';
import {
  apiAdminEmployees, apiAdminCreateEmployee, apiAdminSetEmployeeStatus,
  apiAdminConnectAccounts, apiAdminCreateConnectAccount,
  type AdminEmployee, type ConnectAccount,
} from '../../api/client';

const EMPLOYEE_ROLES = [
  'operations_manager', 'treasury_manager', 'compliance_officer', 'risk_analyst',
  'provider_manager', 'customer_support', 'security_admin', 'technical_admin',
  'business_manager', 'superadmin',
];

const ROLE_LABEL: Record<string, string> = {
  superadmin: 'Super Admin', operations_manager: 'Operations',
  treasury_manager: 'Treasury', compliance_officer: 'Compliance',
  risk_analyst: 'Risk / Fraud', provider_manager: 'Provider',
  customer_support: 'Support', security_admin: 'Security',
  technical_admin: 'Technical', business_manager: 'Business Mgmt',
};

/** Gestion des employés internes — réservé au Super Admin. */
export function AdminEmployeesPage() {
  const [rows, setRows] = useState<AdminEmployee[]>([]);
  const [state, setState] = useState<'loading' | 'error' | 'ready'>('loading');
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ full_name: '', email: '', role: 'operations_manager', department: '' });
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);

  const load = useCallback(async () => {
    setState('loading');
    const res = await apiAdminEmployees();
    if (res.success && res.data) { setRows(res.data.items); setState('ready'); }
    else setState('error');
  }, []);
  useEffect(() => { void load(); }, [load]);

  const create = async () => {
    setSaving(true); setMsg(null);
    const res = await apiAdminCreateEmployee(form);
    setSaving(false);
    if (res.success) { setMsg('Employé créé (invitation envoyée).'); setShowForm(false); setForm({ full_name: '', email: '', role: 'operations_manager', department: '' }); void load(); }
    else setMsg(res.error || 'Erreur.');
  };

  const toggleStatus = async (e: AdminEmployee) => {
    const next = e.status === 'active' ? 'disabled' : 'active';
    await apiAdminSetEmployeeStatus(e.id, next);
    void load();
  };

  return (
    <div className="page">
      <div className="page-header animate-up" style={{ marginBottom: 20 }}>
        <div className="page-label">SUPER ADMIN · GESTION EMPLOYÉS</div>
        <div className="page-title">Employés <span className="gc">internes</span></div>
        <p style={{ marginTop: 8, fontSize: 13, color: 'var(--text-mid)' }}>Créez et gérez les comptes internes Nexus, leurs rôles et permissions.</p>
      </div>

      <div style={{ display: 'flex', gap: 10, marginBottom: 16 }}>
        <button className="btn btn-cyan" onClick={() => setShowForm((v) => !v)}>
          {showForm ? '✕ Fermer' : '+ Créer un employé'}
        </button>
        <button className="btn btn-ghost" onClick={() => void load()}>↻ Actualiser</button>
      </div>

      {msg && <div className="card card-hi-c" style={{ padding: 12, marginBottom: 12, fontSize: 13 }}>{msg}</div>}

      {showForm && (
        <div className="card card-hi-c animate-fade" style={{ padding: 20, marginBottom: 16, maxWidth: 640 }}>
          <div className="form-grid">
            <div className="form-group">
              <label className="form-label">Nom complet</label>
              <input className="input-field" value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} placeholder="Nom complet" />
            </div>
            <div className="form-group">
              <label className="form-label">Email</label>
              <input className="input-field" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} placeholder="email@nexus.com" />
            </div>
            <div className="form-group">
              <label className="form-label">Rôle</label>
              <select className="input-field" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>
                {EMPLOYEE_ROLES.map((r) => <option key={r} value={r}>{ROLE_LABEL[r] ?? r}</option>)}
              </select>
            </div>
            <div className="form-group">
              <label className="form-label">Département</label>
              <input className="input-field" value={form.department} onChange={(e) => setForm({ ...form, department: e.target.value })} placeholder="Operations, Treasury…" />
            </div>
          </div>
          <button className="btn btn-cyan" style={{ marginTop: 16 }} onClick={create} disabled={saving || !form.full_name || !form.email}>
            {saving ? 'Création…' : 'Créer l’employé'}
          </button>
        </div>
      )}

      {state === 'loading' && <div className="card" style={{ padding: 40, textAlign: 'center' }}><div className="nexus-spinner" /></div>}
      {state === 'error' && <div className="card card-hi-g" style={{ padding: 30, textAlign: 'center' }}>Erreur de chargement.</div>}

      {state === 'ready' && rows.length === 0 && (
        <div className="card card-hi-c" style={{ padding: 30, textAlign: 'center', color: 'var(--text-mid)' }}>Aucun employé interne enregistré.</div>
      )}

      {state === 'ready' && rows.length > 0 && (
        <div className="card" style={{ padding: 12 }}>
          {rows.map((e) => (
            <div key={e.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '12px 8px', borderBottom: '1px solid var(--border-soft)' }}>
              <span style={{ fontSize: 20 }}>👤</span>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontWeight: 600, color: 'var(--text-bright)' }}>{e.full_name}</div>
                <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>{e.email}</div>
              </div>
              <div style={{ fontSize: 11, color: 'var(--text-mid)' }}>{e.department || '—'}</div>
              <span className="pill p-c" style={{ fontSize: 9 }}>{ROLE_LABEL[e.role] ?? e.role}</span>
              <span className={`pill ${e.status === 'active' ? 'p-gr' : e.status === 'disabled' ? 'p-r' : 'p-g'}`} style={{ fontSize: 9 }}>{e.status}</span>
              <button className="btn btn-ghost" style={{ fontSize: 10 }} onClick={() => void toggleStatus(e)}>
                {e.status === 'active' ? 'Désactiver' : 'Réactiver'}
              </button>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

/** Gestion des comptes Nexus Connect (B2B/API) — réservé au Super Admin. */
export function AdminConnectAccountsPage() {
  const [rows, setRows] = useState<ConnectAccount[]>([]);
  const [state, setState] = useState<'loading' | 'error' | 'ready'>('loading');
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ company_name: '', email: '', environment: 'sandbox', country: '' });
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);

  const load = useCallback(async () => {
    setState('loading');
    const res = await apiAdminConnectAccounts();
    if (res.success && res.data) { setRows(res.data.items); setState('ready'); }
    else setState('error');
  }, []);
  useEffect(() => { void load(); }, [load]);

  const create = async () => {
    setSaving(true); setMsg(null);
    const res = await apiAdminCreateConnectAccount(form);
    setSaving(false);
    if (res.success) { setMsg('Compte Connect créé.'); setShowForm(false); setForm({ company_name: '', email: '', environment: 'sandbox', country: '' }); void load(); }
    else setMsg(res.error || 'Erreur.');
  };

  return (
    <div className="page">
      <div className="page-header animate-up" style={{ marginBottom: 20 }}>
        <div className="page-label">SUPER ADMIN · NEXUS CONNECT</div>
        <div className="page-title">Comptes <span className="gc">Connect</span> (B2B / API)</div>
        <p style={{ marginTop: 8, fontSize: 13, color: 'var(--text-mid)' }}>Entreprises et fintechs intégrant l’infrastructure Nexus via l’API.</p>
      </div>

      <div style={{ display: 'flex', gap: 10, marginBottom: 16 }}>
        <button className="btn btn-cyan" onClick={() => setShowForm((v) => !v)}>{showForm ? '✕ Fermer' : '+ Créer un compte Connect'}</button>
        <button className="btn btn-ghost" onClick={() => void load()}>↻ Actualiser</button>
      </div>

      {msg && <div className="card card-hi-c" style={{ padding: 12, marginBottom: 12, fontSize: 13 }}>{msg}</div>}

      {showForm && (
        <div className="card card-hi-c animate-fade" style={{ padding: 20, marginBottom: 16, maxWidth: 640 }}>
          <div className="form-grid">
            <div className="form-group">
              <label className="form-label">Entreprise</label>
              <input className="input-field" value={form.company_name} onChange={(e) => setForm({ ...form, company_name: e.target.value })} placeholder="Nom de l'entreprise" />
            </div>
            <div className="form-group">
              <label className="form-label">Email</label>
              <input className="input-field" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} placeholder="dev@entreprise.com" />
            </div>
            <div className="form-group">
              <label className="form-label">Environnement</label>
              <select className="input-field" value={form.environment} onChange={(e) => setForm({ ...form, environment: e.target.value })}>
                <option value="sandbox">Sandbox</option>
                <option value="production">Production</option>
              </select>
            </div>
            <div className="form-group">
              <label className="form-label">Pays (ISO)</label>
              <input className="input-field" maxLength={2} value={form.country} onChange={(e) => setForm({ ...form, country: e.target.value.toUpperCase() })} placeholder="FR" />
            </div>
          </div>
          <button className="btn btn-cyan" style={{ marginTop: 16 }} onClick={create} disabled={saving || !form.company_name || !form.email}>
            {saving ? 'Création…' : 'Créer le compte Connect'}
          </button>
        </div>
      )}

      {state === 'loading' && <div className="card" style={{ padding: 40, textAlign: 'center' }}><div className="nexus-spinner" /></div>}
      {state === 'error' && <div className="card card-hi-g" style={{ padding: 30, textAlign: 'center' }}>Erreur de chargement.</div>}
      {state === 'ready' && rows.length === 0 && (
        <div className="card card-hi-c" style={{ padding: 30, textAlign: 'center', color: 'var(--text-mid)' }}>Aucun compte Connect enregistré.</div>
      )}

      {state === 'ready' && rows.length > 0 && (
        <div className="card" style={{ padding: 12 }}>
          {rows.map((a) => (
            <div key={a.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '12px 8px', borderBottom: '1px solid var(--border-soft)' }}>
              <span style={{ fontSize: 20 }}>🔌</span>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontWeight: 600, color: 'var(--text-bright)' }}>{a.company_name}</div>
                <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>{a.email}</div>
              </div>
              <span className={`pill ${a.environment === 'production' ? 'p-g' : 'p-c'}`} style={{ fontSize: 9 }}>{a.environment}</span>
              <span className={`pill ${a.status === 'active' ? 'p-gr' : a.status === 'suspended' ? 'p-r' : 'p-g'}`} style={{ fontSize: 9 }}>{a.status}</span>
              <span style={{ fontSize: 11, color: 'var(--text-mid)' }}>{a.country || '—'}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
