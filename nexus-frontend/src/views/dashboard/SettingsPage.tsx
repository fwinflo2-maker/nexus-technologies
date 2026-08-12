import { useState, useEffect } from 'react';
import { useI18n } from '../../context/I18nContext';
import { apiGetUserProfile, apiUpdateProfile, apiUpdatePassword, apiGetSessions, apiRevokeSession, type UserProfile, type UserSession } from '../../api/client';

export default function SettingsPage() {
  const { t, lang } = useI18n();
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
    language: lang,
    theme: 'dark',
    notifications_enabled: true,
  });

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
        setSuccess(t('settings_pref_save'));
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
        setError(t('reg_err_password_min'));
        return;
      }

      if (passwordData.new_password !== passwordData.confirm_password) {
        setError('Les mots de passe ne correspondent pas.');
        return;
      }

      const response = await apiUpdatePassword(passwordData);
      if (response.success) {
        setSuccess(t('settings_pref_save'));
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
          <p>{t('dash_session_load')}</p>
        </div>
      );
    }

    return (
      <div className="settings-section animate-up">
        <h3 className="section-title">{t('settings_tab_profile')}</h3>
        
        <div className="form-group">
          <label className="form-label">{t('login_email_label')}</label>
          <input
            type="email"
            value={profile.email}
            disabled
            className="input-field"
            style={{ opacity: 0.6, cursor: 'not-allowed' }}
          />
          <small className="form-hint">{t('settings_email_readonly')}</small>
        </div>

        <div className="form-group">
          <label className="form-label">{t('settings_full_name')}</label>
          {editMode ? (
            <input
              type="text"
              value={formData.full_name}
              onChange={(e) => setFormData({ ...formData, full_name: e.target.value })}
              className="input-field"
              placeholder={t('settings_full_name')}
            />
          ) : (
            <div className="static-value">{profile.full_name || '—'}</div>
          )}
        </div>

        <div className="form-group">
          <label className="form-label">{t('settings_phone')}</label>
          {editMode ? (
            <input
              type="tel"
              value={formData.phone}
              onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
              className="input-field"
              placeholder="+242XXXXXXXXX"
            />
          ) : (
            <div className="static-value">{profile.phone || '—'}</div>
          )}
        </div>

        <div className="form-group">
          <label className="form-label">{t('settings_residence')}</label>
          {editMode ? (
            <select
              value={formData.country_of_residence}
              onChange={(e) => setFormData({ ...formData, country_of_residence: e.target.value })}
              className="input-field"
            >
              <option value="">— Choose —</option>
              <option value="CG">🇨🇬 Congo</option>
              <option value="CM">🇨🇲 Cameroun</option>
              <option value="GA">🇬🇦 Gabon</option>
              <option value="FR">🇫🇷 France</option>
              <option value="SN">🇸🇳 Sénégal</option>
              <option value="CI">🇨🇮 Côte d'Ivoire</option>
            </select>
          ) : (
            <div className="static-value">{profile.kyc_level === 'none' ? t('settings_not_verified') : t('settings_verified_standard')}</div>
          )}
        </div>

        <div className="form-group">
          <label className="form-label">{t('settings_account_type')}</label>
          <div className="static-value">
            {profile.account_type === 'business' ? t('side_business') : t('side_personal')}
          </div>
        </div>

        <div className="form-group">
          <label className="form-label">{t('settings_status_kyc')}</label>
          <div className="static-value">
            {profile.kyc_level === 'none' && '❌ ' + t('settings_not_verified')}
            {profile.kyc_level === 'basic' && '⚠️ ' + t('settings_verified_basic')}
            {profile.kyc_level === 'standard' && '✅ ' + t('settings_verified_standard')}
            {profile.kyc_level === 'advanced' && '✅✅ ' + t('settings_verified_advanced')}
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
                {loading ? '...' : t('settings_pref_save')}
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
                {t('reg_back_btn')}
              </button>
            </>
          ) : (
            <button
              className="btn btn-primary"
              onClick={() => setEditMode(true)}
              disabled={loading}
            >
              {t('send_modify')}
            </button>
          )}
        </div>
      </div>
    );
  }

  function renderSecuritySection() {
    return (
      <div className="settings-section animate-up">
        <h3 className="section-title">{t('settings_tab_security')}</h3>

        <div className="form-group">
          <label className="form-label">{t('settings_current_password')}</label>
          <input
            type="password"
            value={passwordData.current_password}
            onChange={(e) => setPasswordData({ ...passwordData, current_password: e.target.value })}
            className="input-field"
            placeholder="••••••••"
          />
        </div>

        <div className="form-group">
          <label className="form-label">{t('settings_new_password')}</label>
          <input
            type="password"
            value={passwordData.new_password}
            onChange={(e) => setPasswordData({ ...passwordData, new_password: e.target.value })}
            className="input-field"
            placeholder={t('reg_password_ph')}
          />
        </div>

        <div className="form-group">
          <label className="form-label">{t('settings_confirm_password')}</label>
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
            {loading ? '...' : t('settings_pref_save')}
          </button>
        </div>

        <div style={{ marginTop: 32, paddingTop: 32, borderTop: '1px solid var(--border)' }}>
          <h4 style={{ marginBottom: 16 }}>{t('settings_google_auth')}</h4>
          {profile?.auth_provider === 'google' ? (
            <div className="badge badge-success">{t('settings_google_connected')}</div>
          ) : (
            <div className="badge badge-secondary">{t('settings_local_auth')}</div>
          )}
        </div>
      </div>
    );
  }

  function renderSessionsSection() {
    return (
      <div className="settings-section animate-up">
        <h3 className="section-title">{t('settings_tab_sessions')}</h3>
        
        {revokedCount > 0 && (
          <div className="alert alert-info" style={{ marginBottom: 20 }}>
            {revokedCount} session(s) revoked.
          </div>
        )}

        {sessions.length === 0 ? (
          <div style={{ padding: 20, textAlign: 'center', color: 'var(--text-mid)' }}>
            No active sessions.
          </div>
        ) : (
          <div className="table-container">
            <table className="data-table">
              <thead>
                <tr>
                  <th>{t('settings_sessions_jti')}</th>
                  <th>{t('settings_sessions_created')}</th>
                  <th>{t('settings_sessions_activity')}</th>
                  <th>{t('settings_sessions_status')}</th>
                  <th>{t('settings_sessions_action')}</th>
                </tr>
              </thead>
              <tbody>
                {sessions.map((session) => (
                  <tr key={session.jti}>
                    <td style={{ fontFamily: 'monospace', fontSize: 12 }}>
                      {session.jti.substring(0, 16)}...
                    </td>
                    <td>{new Date(session.created_at).toLocaleDateString()}</td>
                    <td>{session.last_activity ? new Date(session.last_activity).toLocaleString() : '-'}</td>
                    <td>
                      {session.is_current ? (
                        <span className="badge badge-success">{t('settings_sessions_current')}</span>
                      ) : (
                        <span className="badge badge-secondary">{t('settings_sessions_active')}</span>
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
                          {t('settings_sessions_revoke')}
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
        <h3 className="section-title">{t('settings_tab_preferences')}</h3>

        <div className="form-group">
          <label className="form-label">{t('settings_pref_language')}</label>
          <input
            type="text"
            value={lang.toUpperCase()}
            disabled
            className="input-field"
            style={{ opacity: 0.6, cursor: 'not-allowed' }}
          />
          <small className="form-hint">{t('settings_pref_language_hint')}</small>
        </div>

        <div className="form-group">
          <label className="form-label">{t('settings_pref_theme')}</label>
          <select
            value={preferences.theme}
            onChange={(e) => setPreferences({ ...preferences, theme: e.target.value })}
            className="input-field"
          >
            <option value="dark">{t('settings_pref_theme_dark')}</option>
            <option value="light">{t('settings_pref_theme_light')}</option>
            <option value="auto">{t('settings_pref_theme_auto')}</option>
          </select>
        </div>

        <div className="form-group">
          <label className="form-label" style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <input
              type="checkbox"
              checked={preferences.notifications_enabled}
              onChange={(e) => setPreferences({ ...preferences, notifications_enabled: e.target.checked })}
            />
            {t('settings_pref_checkbox')}
          </label>
        </div>

        <div className="form-actions" style={{ marginTop: 24 }}>
          <button
            className="btn btn-primary"
            onClick={() => {
              localStorage.setItem('nexus_preferences', JSON.stringify(preferences));
              setSuccess('Preferences saved.');
              setTimeout(() => setSuccess(null), 3000);
            }}
          >
            {t('settings_pref_save')}
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="page">
      <div className="page-header animate-up">
        <div className="page-label">{t('side_compte_perso')}</div>
        <div className="page-title">{t('side_settings')}</div>
        <p className="page-subtitle" style={{ marginTop: 10, fontSize: 14, color: 'var(--text-mid)', maxWidth: 600 }}>
          {t('settings_desc')}
        </p>
      </div>

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
          {t('settings_tab_profile')}
        </button>
        <button
          className={`tab ${activeTab === 'security' ? 'active' : ''}`}
          onClick={() => setActiveTab('security')}
        >
          {t('settings_tab_security')}
        </button>
        <button
          className={`tab ${activeTab === 'sessions' ? 'active' : ''}`}
          onClick={() => setActiveTab('sessions')}
        >
          {t('settings_tab_sessions')}
        </button>
        <button
          className={`tab ${activeTab === 'preferences' ? 'active' : ''}`}
          onClick={() => setActiveTab('preferences')}
        >
          {t('settings_tab_preferences')}
        </button>
      </div>

      {/* Contenu des sections */}
      {activeTab === 'profile' && renderProfileSection()}
      {activeTab === 'security' && renderSecuritySection()}
      {activeTab === 'sessions' && renderSessionsSection()}
      {activeTab === 'preferences' && renderPreferencesSection()}
    </div>
  );
}
