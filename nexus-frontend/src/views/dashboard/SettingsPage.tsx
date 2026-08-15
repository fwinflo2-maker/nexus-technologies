import { useState, useEffect } from 'react';
import { apiGetUserProfile, apiUpdateProfile, apiUpdatePassword, apiGetSessions, apiRevokeSession, type UserProfile, type UserSession } from '../../api/client';

/**
 * Page Paramètres — Gestion complète du compte utilisateur.
 * Sections : Profil, Sécurité, Sessions, Préférences.
 */
export default function SettingsPage() {
  const [activeTab, setActiveTab] = useState<'profile' | 'security' | 'sessions' | 'preferences'>('profile');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  
  // Profil
  const [profile, setProfile] = useState<UserProfile | null>(null);
  const [editMode, setEditMode] = useState(false);
  const [formData, setFormData] = useState({
    full_name: '',
    phone: '',
    country_of_residence: '',
  });

  // Mot de passe
  const [passwordData, setPasswordData] = useState({
    current_password: '',
    new_password: '',
    confirm_password: '',
  });

  // Sessions
  const [sessions, setSessions] = useState<UserSession[]>([]);
  const [revokedCount, setRevokedCount] = useState(0);

  // Préférences (locales pour l'instant)
  const [preferences, setPreferences] = useState({
    language: 'fr',
    theme: 'dark',
    notifications_enabled: true,
  });

  // Charger le profil au montage
  useEffect(() => {
    loadProfile();
    loadSessions();
  }, []);

  async function loadProfile() {
    try {
      setLoading(true);
      setError(null);
      const response = await apiGetUserProfile();
      if (response.success && response.data) {
        const user = response.data.user;
        setProfile(user);
        setFormData({
          full_name: user.full_name || '',
          phone: user.phone || '',
          country_of_residence: '',
        });
      }
    } catch (err) {
      setError('Impossible de charger le profil.');
      console.error(err);
    } finally {
      setLoading(false);
    }
  }

  async function loadSessions() {
    try {
      const response = await apiGetSessions();
      if (response.success && response.data) {
        setSessions(response.data.sessions || []);
        setRevokedCount(response.data.revoked_count || 0);
      }
    } catch (err) {
      console.error('Erreur chargement sessions:', err);
    }
  }

  async function handleUpdateProfile() {
    try {
      setLoading(true);
      setError(null);
      setSuccess(null);
      
      const payload: Record<string, string> = {};
      if (formData.full_name !== '') payload.full_name = formData.full_name;
      if (formData.phone !== '') payload.phone = formData.phone;
      if (formData.country_of_residence !== '') payload.country_of_residence = formData.country_of_residence;

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
        setError('Le nouveau mot de passe doit contenir au moins 8 caractères.');
        return;
      }

      if (passwordData.new_password !== passwordData.confirm_password) {
        setError('Les mots de passe ne correspondent pas.');
        return;
      }

      const response = await apiUpdatePassword(passwordData);
      if (response.success) {
        setSuccess('Mot de passe modifié avec succès.');
        setPasswordData({
          current_password: '',
          new_password: '',
          confirm_password: '',
        });
      }
    } catch (err: any) {
      setError(err.message || 'Erreur lors du changement de mot de passe.');
    } finally {
      setLoading(false);
    }
  }

  async function handleRevokeSession(jti: string) {
    if (!confirm('Voulez-vous vraiment révoquer cette session ?')) return;

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

  function renderProfileSection() {
    if (!profile) {
      return (
        <div className="card" style={{ padding: 40, textAlign: 'center' }}>
          <p>Chargement du profil...</p>
        </div>
      );
    }

    return (
      <div className="settings-section animate-up">
        <h3 className="section-title">Profil</h3>
        
        <div className="form-group">
          <label className="form-label">Adresse e-mail</label>
          <input
            type="email"
            value={profile.email}
            disabled
            className="input-field"
            style={{ opacity: 0.6, cursor: 'not-allowed' }}
          />
          <small className="form-hint">L'e-mail ne peut pas être modifié.</small>
        </div>

        <div className="form-group">
          <label className="form-label">Nom complet</label>
          {editMode ? (
            <input
              type="text"
              value={formData.full_name}
              onChange={(e) => setFormData({ ...formData, full_name: e.target.value })}
              className="input-field"
              placeholder="Votre nom complet"
            />
          ) : (
            <div className="static-value">{profile.full_name || 'Non renseigné'}</div>
          )}
        </div>

        <div className="form-group">
          <label className="form-label">Téléphone</label>
          {editMode ? (
            <input
              type="tel"
              value={formData.phone}
              onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
              className="input-field"
              placeholder="+242XXXXXXXXX"
            />
          ) : (
            <div className="static-value">{profile.phone || 'Non renseigné'}</div>
          )}
        </div>

        <div className="form-group">
          <label className="form-label">Pays de résidence</label>
          {editMode ? (
            <select
              value={formData.country_of_residence}
              onChange={(e) => setFormData({ ...formData, country_of_residence: e.target.value })}
              className="input-field"
            >
              <option value="">Sélectionner un pays</option>
              <option value="CG">🇨🇬 Congo</option>
              <option value="CM">🇨🇲 Cameroun</option>
              <option value="GA">🇬🇦 Gabon</option>
              <option value="FR">🇫🇷 France</option>
              <option value="SN">🇸🇳 Sénégal</option>
              <option value="CI">🇨🇮 Côte d'Ivoire</option>
            </select>
          ) : (
            <div className="static-value">{profile.kyc_level === 'none' ? 'Non renseigné' : 'Défini'}</div>
          )}
        </div>

        <div className="form-group">
          <label className="form-label">Type de compte</label>
          <div className="static-value">
            {profile.account_type === 'business' ? 'Entreprise' : 'Personnel'}
          </div>
        </div>

        <div className="form-group">
          <label className="form-label">Statut KYC</label>
          <div className="static-value">
            {profile.kyc_level === 'none' && '❌ Non vérifié'}
            {profile.kyc_level === 'basic' && '⚠️ Vérification basique'}
            {profile.kyc_level === 'standard' && '✅ Vérification standard'}
            {profile.kyc_level === 'advanced' && '✅✅ Vérification avancée'}
          </div>
        </div>

        <div className="form-actions" style={{ marginTop: 24 }}>
          {editMode ? (
            <>
              <button
                className="btn btn-primary"
                onClick={handleUpdateProfile}
                disabled={loading}
              >
                {loading ? 'Enregistrement...' : 'Enregistrer'}
              </button>
              <button
                className="btn btn-secondary"
                onClick={() => {
                  setEditMode(false);
                  loadProfile();
                }}
                disabled={loading}
                style={{ marginLeft: 12 }}
              >
                Annuler
              </button>
            </>
          ) : (
            <button
              className="btn btn-primary"
              onClick={() => setEditMode(true)}
              disabled={loading}
            >
              Modifier le profil
            </button>
          )}
        </div>
      </div>
    );
  }

  function renderSecuritySection() {
    return (
      <div className="settings-section animate-up">
        <h3 className="section-title">Sécurité</h3>

        <div className="form-group">
          <label className="form-label">Mot de passe actuel</label>
          <input
            type="password"
            value={passwordData.current_password}
            onChange={(e) => setPasswordData({ ...passwordData, current_password: e.target.value })}
            className="input-field"
            placeholder="••••••••"
          />
        </div>

        <div className="form-group">
          <label className="form-label">Nouveau mot de passe</label>
          <input
            type="password"
            value={passwordData.new_password}
            onChange={(e) => setPasswordData({ ...passwordData, new_password: e.target.value })}
            className="input-field"
            placeholder="Minimum 8 caractères"
          />
        </div>

        <div className="form-group">
          <label className="form-label">Confirmer le nouveau mot de passe</label>
          <input
            type="password"
            value={passwordData.confirm_password}
            onChange={(e) => setPasswordData({ ...passwordData, confirm_password: e.target.value })}
            className="input-field"
            placeholder="••••••••"
          />
        </div>

        <div className="form-actions" style={{ marginTop: 24 }}>
          <button
            className="btn btn-primary"
            onClick={handleUpdatePassword}
            disabled={loading}
          >
            {loading ? 'Modification...' : 'Changer le mot de passe'}
          </button>
        </div>

        <div style={{ marginTop: 32, paddingTop: 32, borderTop: '1px solid var(--border)' }}>
          <h4 style={{ marginBottom: 16 }}>Authentification</h4>
          <div className="badge badge-secondary">Authentification locale (email / mot de passe)</div>
        </div>
      </div>
    );
  }

  function renderSessionsSection() {
    return (
      <div className="settings-section animate-up">
        <h3 className="section-title">Sessions actives</h3>
        
        {revokedCount > 0 && (
          <div className="alert alert-info" style={{ marginBottom: 20 }}>
            {revokedCount} session(s) révoquée(s) précédemment.
          </div>
        )}

        {sessions.length === 0 ? (
          <div style={{ padding: 20, textAlign: 'center', color: 'var(--text-mid)' }}>
            Aucune session active affichée.
          </div>
        ) : (
          <div className="table-container">
            <table className="data-table">
              <thead>
                <tr>
                  <th>JTI</th>
                  <th>Créée le</th>
                  <th>Dernière activité</th>
                  <th>Statut</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                {sessions.map((session) => (
                  <tr key={session.jti}>
                    <td style={{ fontFamily: 'monospace', fontSize: 12 }}>
                      {session.jti.substring(0, 16)}...
                    </td>
                    <td>{new Date(session.created_at).toLocaleDateString('fr-FR')}</td>
                    <td>{session.last_activity ? new Date(session.last_activity).toLocaleString('fr-FR') : '-'}</td>
                    <td>
                      {session.is_current ? (
                        <span className="badge badge-success">Actuelle</span>
                      ) : (
                        <span className="badge badge-secondary">Active</span>
                      )}
                    </td>
                    <td>
                      {session.is_current ? (
                        <span style={{ color: 'var(--text-mid)', fontSize: 12 }}>—</span>
                      ) : (
                        <button
                          className="btn btn-sm btn-danger"
                          onClick={() => handleRevokeSession(session.jti)}
                          disabled={loading}
                        >
                          Révoquer
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    );
  }

  function renderPreferencesSection() {
    return (
      <div className="settings-section animate-up">
        <h3 className="section-title">Préférences</h3>

        <div className="form-group">
          <label className="form-label">Langue</label>
          <select
            value={preferences.language}
            onChange={(e) => setPreferences({ ...preferences, language: e.target.value })}
            className="input-field"
          >
            <option value="fr">Français</option>
            <option value="en">English</option>
          </select>
          <small className="form-hint">Stocké localement dans votre navigateur.</small>
        </div>

        <div className="form-group">
          <label className="form-label">Thème</label>
          <select
            value={preferences.theme}
            onChange={(e) => setPreferences({ ...preferences, theme: e.target.value })}
            className="input-field"
          >
            <option value="dark">Sombre</option>
            <option value="light">Clair</option>
            <option value="auto">Automatique</option>
          </select>
        </div>

        <div className="form-group">
          <label className="form-label" style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <input
              type="checkbox"
              checked={preferences.notifications_enabled}
              onChange={(e) => setPreferences({ ...preferences, notifications_enabled: e.target.checked })}
            />
            Activer les notifications
          </label>
        </div>

        <div className="form-actions" style={{ marginTop: 24 }}>
          <button
            className="btn btn-primary"
            onClick={() => {
              localStorage.setItem('nexus_preferences', JSON.stringify(preferences));
              setSuccess('Préférences enregistrées localement.');
              setTimeout(() => setSuccess(null), 3000);
            }}
          >
            Enregistrer les préférences
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="page">
      <div className="page-header animate-up">
        <div className="page-label">COMPTE PERSONNEL</div>
        <div className="page-title">Paramètres</div>
        <p className="page-subtitle" style={{ marginTop: 10, fontSize: 14, color: 'var(--text-mid)', maxWidth: 600 }}>
          Gérez votre profil, votre sécurité et vos préférences de compte.
        </p>
      </div>

      {/* Conteneur central : onglets + sections */}
      <div style={{
        maxWidth: 720, margin: '0 auto', padding: 24,
        background: 'linear-gradient(180deg, rgba(255,255,255,.015), transparent 40%), var(--panel)',
        border: '1px solid var(--border)', borderRadius: 'var(--r-lg)',
      }}>

      {/* Messages d'erreur et succès */}
      {error && (
        <div className="alert alert-error animate-up" style={{ marginBottom: 20 }}>
          {error}
        </div>
      )}
      {success && (
        <div className="alert alert-success animate-up" style={{ marginBottom: 20 }}>
          {success}
        </div>
      )}

      {/* Onglets de navigation */}
      <div className="tabs animate-up" style={{ marginBottom: 24 }}>
        <button
          className={`tab ${activeTab === 'profile' ? 'active' : ''}`}
          onClick={() => setActiveTab('profile')}
        >
          Profil
        </button>
        <button
          className={`tab ${activeTab === 'security' ? 'active' : ''}`}
          onClick={() => setActiveTab('security')}
        >
          Sécurité
        </button>
        <button
          className={`tab ${activeTab === 'sessions' ? 'active' : ''}`}
          onClick={() => setActiveTab('sessions')}
        >
          Sessions
        </button>
        <button
          className={`tab ${activeTab === 'preferences' ? 'active' : ''}`}
          onClick={() => setActiveTab('preferences')}
        >
          Préférences
        </button>
      </div>

      {/* Contenu des sections */}
      {activeTab === 'profile' && renderProfileSection()}
      {activeTab === 'security' && renderSecuritySection()}
      {activeTab === 'sessions' && renderSessionsSection()}
      {activeTab === 'preferences' && renderPreferencesSection()}

      </div>
    </div>
  );
}
