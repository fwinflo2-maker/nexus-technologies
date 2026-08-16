import { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { apiTeamList, apiTeamAdd, apiTeamUpdate, apiTeamRemove, type TeamMember } from '../../api/client';
import { pillForStatus } from './ui';
import { useDashT } from '../../data/dashboard-i18n';

const ROLES = ['admin', 'finance_manager', 'accountant', 'operator', 'viewer'];
const ROLE_LABEL: Record<string, string> = {
  owner: 'Owner', admin: 'Admin', finance_manager: 'Finance Manager',
  accountant: 'Accountant', operator: 'Operator', viewer: 'Viewer',
};

/** Équipe & permissions (RBAC réel, vérifié côté backend). */
export default function TeamPage() {
  const t = useDashT();
  const [members, setMembers] = useState<TeamMember[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [banner, setBanner] = useState<string | null>(null);
  const [email, setEmail] = useState('');
  const [role, setRole] = useState('operator');
  const [saving, setSaving] = useState(false);

  const fetch = useCallback(async () => {
    setLoading(true);
    setError(null);
    const res = await apiTeamList();
    if (!res.success || !res.data) { setError(res.error || 'Erreur de chargement.'); setLoading(false); return; }
    setMembers(res.data.items);
    setLoading(false);
  }, []);

  useEffect(() => { fetch(); }, [fetch]);

  const add = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setBanner(null);
    const res = await apiTeamAdd({ email, role });
    setSaving(false);
    if (!res.success) { setBanner('⚠️ ' + (res.error || 'Ajout impossible.')); return; }
    setBanner('✅ Membre ajouté.');
    setEmail('');
    fetch();
  };

  const changeRole = async (m: TeamMember, newRole: string) => {
    setBanner(null);
    const res = await apiTeamUpdate(m.id, newRole);
    if (!res.success) { setBanner('⚠️ ' + (res.error || 'Mise à jour impossible.')); return; }
    fetch();
  };

  const remove = async (m: TeamMember) => {
    setBanner(null);
    const res = await apiTeamRemove(m.id);
    if (!res.success) { setBanner('⚠️ ' + (res.error || 'Suppression impossible.')); return; }
    fetch();
  };

  return (
    <div className="page">
      <motion.div className="page-header animate-up" initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>
        <div className="page-label">{t('nav.team').toUpperCase()}</div>
        <div className="page-title">{t('page.team')}</div>
        <p style={{ marginTop: 10, fontSize: 13, color: 'var(--text-mid)' }}>Rôles : Owner, Admin, Finance Manager, Accountant, Operator, Viewer. Les permissions sont appliquées côté backend.</p>
      </motion.div>

      {banner && <div className="card card-hi-c" style={{ padding: 14, marginBottom: 16, fontSize: 13 }}>{banner}</div>}

      <div className="card" style={{ padding: 20, marginBottom: 20 }}>
        <div className="page-label" style={{ marginBottom: 14 }}>{t('form.add_member')}</div>
        <form onSubmit={add} style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'flex-end' }}>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11, color: 'var(--text-dim)' }}>Email du compte NEXUS
            <input className="form-control" style={{ width: 260 }} type="email" value={email} onChange={e => setEmail(e.target.value)} placeholder="membre@exemple.com" required />
          </label>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11, color: 'var(--text-dim)' }}>Rôle
            <select className="form-control" style={{ width: 160 }} value={role} onChange={e => setRole(e.target.value)}>
              {ROLES.map(r => <option key={r} value={r}>{ROLE_LABEL[r]}</option>)}
            </select>
          </label>
          <button className="se-cta" type="submit" disabled={saving} style={{ fontSize: 12 }}>{saving ? '…' : `+ ${t('common.add')}`}</button>
        </form>
      </div>

      {loading ? (
        <div className="card card-hi-c" style={{ padding: 40, textAlign: 'center' }}><div className="nexus-spinner" /></div>
      ) : error ? (
        <div className="card card-hi-g" style={{ padding: 40, textAlign: 'center' }}><p style={{ color: 'var(--text-mid)', marginBottom: 12 }}>{error}</p><button className="se-cta" onClick={fetch}>↻ Réessayer</button></div>
      ) : members.length === 0 ? (
        <div className="card card-hi-c" style={{ padding: 40, textAlign: 'center' }}>
          <div style={{ fontSize: 40, marginBottom: 12 }}>👤</div>
          <p style={{ color: 'var(--text-mid)' }}>{t('empty.noMembers')}</p>
        </div>
      ) : (
        <div className="card" style={{ padding: 12 }}>
          {members.map(m => (
            <div key={m.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: 14, borderBottom: '1px solid var(--border)', flexWrap: 'wrap' }}>
              <div style={{ flex: 1, minWidth: 200 }}>
                <div style={{ fontWeight: 700, color: 'var(--text-bright)' }}>{m.full_name}</div>
                <div style={{ fontSize: 12, color: 'var(--text-mid)' }}>{m.email}</div>
              </div>
              <span className={`pill ${pillForStatus('active')}`} style={{ fontSize: 10 }}>{ROLE_LABEL[m.role] ?? m.role}</span>
              <select className="form-control" style={{ width: 160 }} value={m.role} onChange={e => changeRole(m, e.target.value)}>
                {ROLES.map(r => <option key={r} value={r}>{ROLE_LABEL[r]}</option>)}
              </select>
              <button className="pill p-r" style={{ cursor: 'pointer', fontSize: 11 }} onClick={() => remove(m)}>{t('common.remove')}</button>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
