import { useState, useEffect } from 'react';
import { apiGetUserProfile, apiUpdateProfile, apiUpdatePassword, apiGetSessions, apiRevokeSession, type UserProfile, type UserSession } from '../../api/client';

export default function AdminAccountSettings() {
  const [profile, setProfile] = useState<UserProfile | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [editMode, setEditMode] = useState(false);
  const [formData, setFormData] = useState({ full_name: '', phone: '' });
  const [passwordData, setPasswordData] = useState({
    current_password: '',
    new_password: '',
    confirm_password: '',
  });
  const [sessions, setSessions] = useState<UserSession[]>([]);
  const [sessionsLoading, setSessionsLoading] = useState(false);

  useEffect(() => {
    loadProfile();
    loadSessions();
  }, []);

  async function loadProfile() {
    try {
      setLoading(true);
      const response = await apiGetUserProfile();
      if (response.success && response.data) {
        const user = response.data.user;
        setProfile(user);
        setFormData({ full_name: user.full_name || '', phone: user.phone || '' });
      }
    } catch (err) {
      setError('Impossible de charger le profil.');
    } finally {
      setLoading(false);
    }
  }

  async function loadSessions() {
    try {
      setSessionsLoading(true);
      const response = await apiGetSessions();
      if (response.success && response.data) {
        setSessions(response.data.sessions || []);
      }
    } catch (err) {
      console.error('Erreur chargement sessions:', err);
    } finally {
      setSessionsLoading(false);
    }
  }

  async function handleRevokeSession(jti: string) {
    try {
      setLoading(true);
      const response = await apiRevokeSession(jti);
      if (response.success) {
        setSuccess('Session révoquée avec succès.');
        loadSessions();
      }
    } catch (err: any) {
      setError(err.message || 'Erreur lors de la révocation de la session.');
    } finally {
      setLoading(false);
    }
  }

  async function handleUpdateProfile() {
    try {
      setLoading(true);
      setError(null);
      setSuccess(null);
      const payload: Record<string, string> = {};
      if (formData.full_name !== (profile?.full_name || '')) payload.full_name = formData.full_name;
      if (formData.phone !== (profile?.phone || '')) payload.phone = formData.phone;
      if (Object.keys(payload).length === 0) {
        setError('Aucune modification à enregistrer.');
        return;
      }
      const response = await apiUpdateProfile(payload);
      if (response.success) {
        setSuccess('Profil mis à jour avec succès.');
        setEditMode(false);
        loadProfile();
      }
    } catch (err: any) {
      setError(err.message || 'Erreur lors de la mise à jour du profil.');
    } finally {
      setLoading(false);
    }
  }

  async function handleUpdatePassword() {
    try {
      setLoading(true);
      setError(null);
      setSuccess(null);
      if (passwordData.new_password.length < 8) {
        setError('Le mot de passe doit contenir au moins 8 caractères.');
        return;
      }
      if (passwordData.new_password !== passwordData.confirm_password) {
        setError('Les mots de passe ne correspondent pas.');
        return;
      }
      const response = await apiUpdatePassword(passwordData);
      if (response.success) {
        setSuccess('Mot de passe modifié avec succès.');
        setPasswordData({ current_password: '', new_password: '', confirm_password: '' });
      }
    } catch (err: any) {
      setError(err.message || 'Erreur lors du changement de mot de passe.');
    } finally {
      setLoading(false);
    }
  }

  if (loading && !profile) {
    return <div className="card" style={{ padding: 40, textAlign: 'center' }}><div className="nexus-spinner" /></div>;
  }

  return (
    <div style={{ maxWidth: 700 }}>
      {error && <div className="alert alert-error" style={{ marginBottom: 16 }}>{error}</div>}
      {success && <div className="alert alert-success" style={{ marginBottom: 16 }}>{success}</div>}

      {/* Profil */}
      <div className="card" style={{ padding: 20, marginBottom: 20 }}>
        <h3 style={{ fontSize: 15, fontWeight: 700, marginBottom: 16 }}>👤 Informations du compte</h3>
        <div style={{ display: 'grid', gap: 14 }}>
          <div>
            <label style={{ fontSize: 11, color: 'var(--text-dim)', display: 'block', marginBottom: 4 }}>Email</label>
            <input type="email" value={profile?.email || ''} disabled className="input-field" style={{ opacity: 0.6 }} />
            <small style={{ fontSize: 10, color: 'var(--text-dim)' }}>L'email ne peut pas être modifié.</small>
          </div>
          <div>
            <label style={{ fontSize: 11, color: 'var(--text-dim)', display: 'block', marginBottom: 4 }}>Nom complet</label>
            {editMode ? (
              <input type="text" value={formData.full_name} onChange={(e) => setFormData({ ...formData, full_name: e.target.value })} className="input-field" />
            ) : (
              <div className="static-value">{profile?.full_name || 'Non renseigné'}</div>
            )}
          </div>
          <div>
            <label style={{ fontSize: 11, color: 'var(--text-dim)', display: 'block', marginBottom: 4 }}>Téléphone</label>
            {editMode ? (
              <input type="tel" value={formData.phone} onChange={(e) => setFormData({ ...formData, phone: e.target.value })} className="input-field" placeholder="+242XXXXXXXXX" />
            ) : (
              <div className="static-value">{profile?.phone || 'Non renseigné'}</div>
            )}
          </div>
          <div>
            <label style={{ fontSize: 11, color: 'var(--text-dim)', display: 'block', marginBottom: 4 }}>Rôle</label>
            <div className="static-value">Super Admin</div>
          </div>
        </div>
        <div style={{ marginTop: 16, display: 'flex', gap: 10 }}>
          {editMode ? (
            <>
              <button className="btn btn-primary" onClick={handleUpdateProfile} disabled={loading}>{loading ? 'Enregistrement...' : 'Enregistrer'}</button>
              <button className="btn btn-ghost" onClick={() => { setEditMode(false); loadProfile(); }}>Annuler</button>
            </>
          ) : (
            <button className="btn btn-primary" onClick={() => setEditMode(true)}>Modifier le profil</button>
          )}
        </div>
      </div>

      {/* Mot de passe */}
      <div className="card" style={{ padding: 20 }}>
        <h3 style={{ fontSize: 15, fontWeight: 700, marginBottom: 16 }}>🔐 Changer le mot de passe</h3>
        <div style={{ display: 'grid', gap: 14 }}>
          <div>
            <label style={{ fontSize: 11, color: 'var(--text-dim)', display: 'block', marginBottom: 4 }}>Mot de passe actuel</label>
            <input type="password" value={passwordData.current_password} onChange={(e) => setPasswordData({ ...passwordData, current_password: e.target.value })} className="input-field" placeholder="••••••••" />
          </div>
          <div>
            <label style={{ fontSize: 11, color: 'var(--text-dim)', display: 'block', marginBottom: 4 }}>Nouveau mot de passe</label>
            <input type="password" value={passwordData.new_password} onChange={(e) => setPasswordData({ ...passwordData, new_password: e.target.value })} className="input-field" placeholder="Minimum 8 caractères" />
          </div>
          <div>
            <label style={{ fontSize: 11, color: 'var(--text-dim)', display: 'block', marginBottom: 4 }}>Confirmer le nouveau mot de passe</label>
            <input type="password" value={passwordData.confirm_password} onChange={(e) => setPasswordData({ ...passwordData, confirm_password: e.target.value })} className="input-field" placeholder="••••••••" />
          </div>
        </div>
        <div style={{ marginTop: 16 }}>
          <button className="btn btn-primary" onClick={handleUpdatePassword} disabled={loading}>{loading ? 'Modification...' : 'Changer le mot de passe'}</button>
        </div>
      </div>

      {/* Sessions actives */}
      <div className="card" style={{ padding: 20, marginTop: 20 }}>
        <h3 style={{ fontSize: 15, fontWeight: 700, marginBottom: 16 }}>🔒 Sessions actives</h3>
        {sessionsLoading ? (
          <div style={{ textAlign: 'center', padding: 20 }}><div className="nexus-spinner" /></div>
        ) : sessions.length === 0 ? (
          <p style={{ fontSize: 12, color: 'var(--text-dim)' }}>Aucune session active.</p>
        ) : (
          <div style={{ display: 'grid', gap: 10 }}>
            {sessions.map((session) => (
              <div key={session.jti} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '10px 12px', background: 'var(--bg-tertiary)', borderRadius: 8, border: '1px solid var(--border)' }}>
                <div>
                  <div style={{ fontSize: 12, fontWeight: 600 }}>{session.device || 'Appareil inconnu'}</div>
                  <div style={{ fontSize: 10, color: 'var(--text-dim)' }}>{session.ip_address || 'IP inconnue'}</div>
                  <div style={{ fontSize: 10, color: 'var(--text-dim)' }}>Créée: {new Date(session.created_at).toLocaleString('fr-FR')}</div>
                  {session.is_current && <span style={{ fontSize: 9, color: '#10B981', fontWeight: 700 }}>● Actuelle</span>}
                </div>
                {!session.is_current && (
                  <button className="btn btn-ghost" onClick={() => handleRevokeSession(session.jti)} style={{ fontSize: 11, padding: '4px 10px' }}>Révoquer</button>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
