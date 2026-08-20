import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { apiGetUserProfile, apiUpdateProfile, apiUpdatePassword, apiGetSessions, apiRevokeSession, type UserProfile, type UserSession } from '../../api/client';
import { useDashT, localeFor } from '../../data/dashboard-i18n';
import { useI18n } from '../../context/I18nContext';
import { countries } from '../../data/countries';
import { useAuth } from '../../context/AuthContext';
import Avatar from '../../components/Avatar';

/** Libellé lisible d'un rôle plateforme interne (uniquement affiché pour les
 *  rôles non-client ; un compte client standard voit toujours `user`). */
function roleLabel(role: string): string {
  const labels: Record<string, string> = {
    superadmin: 'Super Admin',
    compliance_officer: 'Compliance',
    provider_engineer: 'Provider Engineer',
    support_operator: 'Support',
    sre_operator: 'SRE',
  };
  return labels[role] ?? role;
}

/**
 * Page Paramètres — Gestion complète du compte utilisateur.
 * Sections : Profil, Sécurité, Sessions, Préférences.
 * Tous les textes visibles passent par l'i18n (dashTranslate).
 *
 * `hideHeader` : utilisé quand la page est embarquée dans une autre surface
 * (ex. console Super Admin) qui fournit déjà son propre en-tête.
 */
export default function SettingsPage({ hideHeader = false }: { hideHeader?: boolean }) {
  const t = useDashT();
  const { lang } = useI18n();
  const locale = localeFor(lang);
  const { refreshSession } = useAuth();

  const [activeTab, setActiveTab] = useState<'profile' | 'security' | 'sessions'>('profile');
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

  // Avatar
  const [avatarPreview, setAvatarPreview] = useState<string | null>(null);
  const [avatarToSave, setAvatarToSave] = useState<string | null>(null);
  const [avatarSaving, setAvatarSaving] = useState(false);

  // Mot de passe
  const [passwordData, setPasswordData] = useState({
    current_password: '',
    new_password: '',
    confirm_password: '',
  });

  // Sessions
  const [sessions, setSessions] = useState<UserSession[]>([]);
  const [revokedCount, setRevokedCount] = useState(0);

  // Charger le profil au montage
  useEffect(() => {
    loadProfile();
    loadSessions();
    // eslint-disable-next-line react-hooks/exhaustive-deps
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
          country_of_residence: user.country_of_residence || '',
        });
      }
    } catch (err) {
      setError(t('settings.error.loadProfile'));
      console.error(err);
    } finally {
      setLoading(false);
    }
  }

  /** Lit le fichier image choisi et en fait un aperçu + data URI à enregistrer. */
  function handleAvatarChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;
    if (file.size > 500000) {
      setError(t('settings.error.avatarSize'));
      return;
    }
    const reader = new FileReader();
    reader.onload = () => {
      const dataUri = reader.result as string;
      setAvatarPreview(dataUri);
      setAvatarToSave(dataUri);
      setError(null);
    };
    reader.readAsDataURL(file);
  }

  /** Enregistre l'avatar via l'API réelle (PUT /api/users/me) + sync AuthContext. */
  async function saveAvatar(dataUri: string | null = avatarToSave) {
    if (dataUri === null) return;
    setAvatarSaving(true);
    setError(null);
    try {
      const resp = await apiUpdateProfile({ avatar: dataUri });
      if (resp.success) {
        setSuccess(t('settings.success.avatar'));
        setAvatarPreview(null);
        setAvatarToSave(null);
        await refreshSession();
        await loadProfile();
      } else {
        setError(resp.error || t('settings.error.avatar'));
      }
    } catch (err: any) {
      setError(err?.message || t('settings.error.avatar'));
    } finally {
      setAvatarSaving(false);
    }
  }

  async function removeAvatar() {
    setAvatarPreview(null);
    setAvatarToSave(null);
    await saveAvatar('');
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
        setError(t('settings.error.noChanges'));
        return;
      }

      const response = await apiUpdateProfile(payload);
      if (response.success) {
        const sumsub = (response.data as { sumsub?: { reverification_required?: boolean } } | undefined)?.sumsub;
        setSuccess(
          sumsub?.reverification_required
            ? t('settings.success.countrySumsub')
            : t('settings.success.profile')
        );
        setEditMode(false);
        await refreshSession();
        loadProfile();
      }
    } catch (err: any) {
      setError(err.message || t('settings.error.profile'));
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
        setError(t('settings.error.passwordLength'));
        return;
      }

      if (passwordData.new_password !== passwordData.confirm_password) {
        setError(t('settings.error.passwordMismatch'));
        return;
      }

      const response = await apiUpdatePassword(passwordData);
      if (response.success) {
        setSuccess(t('settings.success.password'));
        setPasswordData({
          current_password: '',
          new_password: '',
          confirm_password: '',
        });
      }
    } catch (err: any) {
      setError(err.message || t('settings.error.password'));
    } finally {
      setLoading(false);
    }
  }

  async function handleRevokeSession(jti: string) {
    if (!confirm(t('settings.confirm.revoke'))) return;

    try {
      setLoading(true);
      const response = await apiRevokeSession(jti);
      if (response.success) {
        setSuccess(t('settings.success.session'));
        loadSessions();
      }
    } catch (err: any) {
      setError(err.message || t('settings.error.session'));
    } finally {
      setLoading(false);
    }
  }

  function renderProfileSection() {
    if (!profile) {
      return (
        <div className="card" style={{ padding: 40, textAlign: 'center' }}>
          <p>{t('settings.loading.profile')}</p>
        </div>
      );
    }

    return (
      <div className="settings-section animate-up">
        <h3 className="section-title">{t('settings.profile.title')}</h3>

        {/* Photo de profil — synchronisée avec sidebar / topbar / dashboard */}
        <div className="form-group" style={{ marginBottom: 8 }}>
          <label className="form-label">{t('settings.profile.avatar')}</label>
          <div style={{ display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap' }}>
            <Avatar
              avatar={avatarPreview ?? profile.avatar}
              accountType={profile.account_type}
              size={72}
            />
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              <label className="btn btn-ghost" style={{ fontSize: 11, cursor: 'pointer', display: 'inline-flex', width: 'fit-content' }}>
                {t('settings.profile.choose')}
                <input
                  type="file"
                  accept="image/*"
                  style={{ display: 'none' }}
                  onChange={handleAvatarChange}
                />
              </label>
              <div style={{ display: 'flex', gap: 8 }}>
                {(avatarPreview || profile.avatar) && (
                  <button className="btn btn-ghost" style={{ fontSize: 11 }} onClick={() => void removeAvatar()} disabled={avatarSaving}>
                    {t('settings.profile.delete')}
                  </button>
                )}
                {avatarPreview && (
                  <button className="btn btn-cyan" style={{ fontSize: 11 }} onClick={() => void saveAvatar()} disabled={avatarSaving}>
                    {avatarSaving ? t('settings.profile.saving') : t('settings.profile.save')}
                  </button>
                )}
              </div>
              <span className="form-hint">{t('settings.profile.avatarHint')}</span>
            </div>
          </div>
        </div>

        <div className="form-grid">
        <div className="form-group form-group--full">
          <label className="form-label">{t('settings.profile.email')}</label>
          <input
            type="email"
            value={profile.email}
            disabled
            className="input-field"
            style={{ opacity: 0.6, cursor: 'not-allowed' }}
          />
          <small className="form-hint">{t('settings.profile.emailHint')}</small>
        </div>

        <div className="form-group">
          <label className="form-label">{t('settings.profile.fullName')}</label>
          {editMode ? (
            <input
              type="text"
              value={formData.full_name}
              onChange={(e) => setFormData({ ...formData, full_name: e.target.value })}
              className="input-field"
              placeholder={t('settings.profile.fullNamePlaceholder')}
            />
          ) : (
            <div className="static-value">{profile.full_name || t('settings.profile.notSet')}</div>
          )}
        </div>

        <div className="form-group">
          <label className="form-label">{t('settings.profile.phone')}</label>
          {editMode ? (
            <input
              type="tel"
              value={formData.phone}
              onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
              className="input-field"
              placeholder={t('settings.profile.phonePlaceholder')}
            />
          ) : (
            <div className="static-value">{profile.phone || t('settings.profile.notSet')}</div>
          )}
        </div>

        <div className="form-group">
          <label className="form-label">{t('settings.profile.country')}</label>
          {editMode ? (
            <>
              <select
                value={formData.country_of_residence}
                onChange={(e) => setFormData({ ...formData, country_of_residence: e.target.value })}
                className="input-field"
              >
                <option value="">{t('settings.profile.selectCountry')}</option>
                {countries.map((c) => (
                  <option key={c.code} value={c.code}>{c.name} ({c.code})</option>
                ))}
              </select>
              <small className="form-hint">{t('settings.profile.countrySumsubHint')}</small>
              <Link to="/kyc" className="btn btn-ghost" style={{ fontSize: 11, marginTop: 8, width: 'fit-content', textDecoration: 'none' }}>
                {t('settings.profile.openSumsub')} →
              </Link>
            </>
          ) : (
            <>
              <div className="static-value">
                {profile.country_of_residence
                  ? (countries.find((c) => c.code === profile.country_of_residence)?.name
                    ?? profile.country_of_residence)
                  : t('settings.profile.notSet')}
              </div>
              <small className="form-hint">{t('settings.profile.countrySumsubHint')}</small>
              <Link to="/kyc" className="btn btn-ghost" style={{ fontSize: 11, marginTop: 8, width: 'fit-content', textDecoration: 'none' }}>
                {t('settings.profile.changeCountryViaSumsub')} →
              </Link>
            </>
          )}
        </div>

        <div className="form-group">
          <label className="form-label">{t('settings.profile.accountType')}</label>
          <div className="static-value">
            {profile.account_type === 'business' ? t('settings.profile.business') : t('settings.profile.personal')}
            {profile.platform_role && profile.platform_role !== 'user' && (
              <span style={{ color: 'var(--cyan)', fontWeight: 600 }}> · {roleLabel(profile.platform_role)}</span>
            )}
          </div>
        </div>

        <div className="form-group">
          <label className="form-label">{t('settings.profile.kyc')}</label>
          <div className="static-value">
            {profile.kyc_level === 'none' && t('settings.profile.kyc.none')}
            {profile.kyc_level === 'basic' && t('settings.profile.kyc.basic')}
            {profile.kyc_level === 'standard' && t('settings.profile.kyc.standard')}
            {profile.kyc_level === 'advanced' && t('settings.profile.kyc.advanced')}
          </div>
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
                {loading ? t('settings.profile.savingShort') : t('settings.profile.saveShort')}
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
                {t('common.cancel')}
              </button>
            </>
          ) : (
            <button
              className="btn btn-primary"
              onClick={() => setEditMode(true)}
              disabled={loading}
            >
              {t('settings.profile.edit')}
            </button>
          )}
        </div>
      </div>
    );
  }

  function renderSecuritySection() {
    return (
      <div className="settings-section animate-up">
        <h3 className="section-title">{t('settings.security.title')}</h3>

        <div className="form-group">
          <label className="form-label">{t('settings.security.currentPassword')}</label>
          <input
            type="password"
            value={passwordData.current_password}
            onChange={(e) => setPasswordData({ ...passwordData, current_password: e.target.value })}
            className="input-field"
            placeholder="••••••••"
          />
        </div>

        <div className="form-group">
          <label className="form-label">{t('settings.security.newPassword')}</label>
          <input
            type="password"
            value={passwordData.new_password}
            onChange={(e) => setPasswordData({ ...passwordData, new_password: e.target.value })}
            className="input-field"
            placeholder={t('settings.security.newPasswordPlaceholder')}
          />
        </div>

        <div className="form-group">
          <label className="form-label">{t('settings.security.confirmPassword')}</label>
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
            {loading ? t('settings.security.changing') : t('settings.security.change')}
          </button>
        </div>

        <div style={{ marginTop: 32, paddingTop: 32, borderTop: '1px solid var(--border)' }}>
          <h4 style={{ marginBottom: 16 }}>{t('settings.security.auth')}</h4>
          <div className="badge badge-secondary">{t('settings.security.authLocal')}</div>
        </div>
      </div>
    );
  }

  function renderSessionsSection() {
    return (
      <div className="settings-section animate-up">
        <h3 className="section-title">{t('settings.sessions.title')}</h3>
        
        {revokedCount > 0 && (
          <div className="alert alert-info" style={{ marginBottom: 20 }}>
            {t('settings.sessions.revoked', { count: revokedCount })}
          </div>
        )}

        {sessions.length === 0 ? (
          <div style={{ padding: 20, textAlign: 'center', color: 'var(--text-mid)' }}>
            {t('settings.sessions.empty')}
          </div>
        ) : (
          <div className="table-container">
            <table className="data-table">
              <thead>
                <tr>
                  <th>{t('settings.sessions.jti')}</th>
                  <th>{t('settings.sessions.created')}</th>
                  <th>{t('settings.sessions.lastActivity')}</th>
                  <th>{t('settings.sessions.status')}</th>
                  <th>{t('settings.sessions.action')}</th>
                </tr>
              </thead>
              <tbody>
                {sessions.map((session) => (
                  <tr key={session.jti}>
                    <td style={{ fontFamily: 'monospace', fontSize: 12 }}>
                      {session.jti.substring(0, 16)}...
                    </td>
                    <td>{new Date(session.created_at).toLocaleDateString(locale)}</td>
                    <td>{session.last_activity ? new Date(session.last_activity).toLocaleString(locale) : '-'}</td>
                    <td>
                      {session.is_current ? (
                        <span className="badge badge-success">{t('settings.sessions.current')}</span>
                      ) : (
                        <span className="badge badge-secondary">{t('settings.sessions.active')}</span>
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
                          {t('settings.sessions.revoke')}
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

  return (
    <div className="page">
      {!hideHeader && (
        <div className="page-header animate-up">
          <div className="page-label">{t('settings.pageLabel')}</div>
          <div className="page-title">{t('settings.title')}</div>
          <p className="page-subtitle" style={{ marginTop: 10, fontSize: 14, color: 'var(--text-mid)', maxWidth: 600 }}>
            {t('settings.subtitle')}
          </p>
        </div>
      )}

      {/* Conteneur central : onglets + sections */}
      <div style={{
        width: '100%', maxWidth: 'none', margin: 0, padding: 24,
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
          {t('settings.tab.profile')}
        </button>
        <button
          className={`tab ${activeTab === 'security' ? 'active' : ''}`}
          onClick={() => setActiveTab('security')}
        >
          {t('settings.tab.security')}
        </button>
        <button
          className={`tab ${activeTab === 'sessions' ? 'active' : ''}`}
          onClick={() => setActiveTab('sessions')}
        >
          {t('settings.tab.sessions')}
        </button>
      </div>

      {/* Contenu des sections */}
      {activeTab === 'profile' && renderProfileSection()}
      {activeTab === 'security' && renderSecuritySection()}
      {activeTab === 'sessions' && renderSessionsSection()}

      </div>
    </div>
  );
}
