import { useState, useEffect, useCallback } from 'react';
import {
  apiAccountsList,
  apiAccountsCreate,
  apiAccountsUpdate,
  apiAccountsDelete,
  apiAccountsSetDefault,
  apiCryptoNetworks,
  apiCashPickupNetworks,
  apiFundingPaymentMethods,
  type PaymentAccount,
  type AccountKind,
  type AccountPayload,
} from '../../api/client';
import { countries } from '../../data/countries';
import { getOperatorsForCountry } from '../../data/mobile-money';
import { paymentModesForCountry } from '../../data/payment-modes';
import { useDashT } from '../../data/dashboard-i18n';
import { useAuth } from '../../context/AuthContext';

type AccountRole = 'source' | 'destination';

interface AccountsPanelProps {
  role: AccountRole;
}

const KIND_META: Record<AccountKind, { icon: string; iconDest: string; ibCls: string; pillCls: string; color: string; label: string }> = {
  bank_iban:     { icon: '💶', iconDest: '🏦', ibCls: 'ib-c',  pillCls: 'p-c',  color: 'var(--cyan)',   label: 'IBAN bancaire' },
  mobile_money:  { icon: '📱', iconDest: '📱', ibCls: 'ib-gr', pillCls: 'p-gr', color: 'var(--green)',  label: 'Mobile Money' },
  crypto_wallet: { icon: '🔵', iconDest: '🔵', ibCls: 'ib-v',  pillCls: 'p-v',  color: 'var(--violet)', label: 'Crypto Wallet' },
  card:          { icon: '💳', iconDest: '💳', ibCls: 'ib-g',  pillCls: 'p-g',  color: 'var(--gold)',   label: 'Carte' },
  virtual_iban:  { icon: '🏢', iconDest: '🏢', ibCls: 'ib-g',  pillCls: 'p-g',  color: 'var(--gold)',   label: 'IBAN virtuel' },
  cash_pickup:   { icon: '💵', iconDest: '💵', ibCls: 'ib-gr', pillCls: 'p-gr', color: 'var(--green)',  label: 'Cash pickup' },
};

/** Choisit l'icône d'un kind selon le rôle (source vs destination). */
function kindIcon(kind: AccountKind, role: AccountRole): string {
  const meta = KIND_META[kind];
  return role === 'destination' ? meta.iconDest : meta.icon;
}

function kindsForCountry(country: string, role: AccountRole): { kinds: AccountKind[]; currency: string } {
  const local = paymentModesForCountry(country);
  if (!local) return { kinds: ['bank_iban', 'crypto_wallet'], currency: 'EUR' };
  return {
    kinds: local.account_kinds[role],
    currency: local.default_currency,
  };
}

/**
 * AccountsPanel — CRUD des sources de financement / destinations.
 *
 * - Liste les comptes de l'utilisateur (filtrés par rôle).
 * - Création / édition via modal avec validation par kind.
 * - Définit un compte par défaut et le supprime (avec promotion du suivant).
 * - Toutes les données sensibles (IBAN, PAN, téléphone, adresse) sont
 *   chiffrées côté serveur et masquées à l'affichage.
 * - Les kinds proposés suivent le pays d’enregistrement (sources) ou le pays saisi (destinations).
 */
export default function AccountsPanel({ role }: AccountsPanelProps) {
  const t = useDashT();
  const { user } = useAuth();
  const registrationCountry = (user?.country_of_residence ?? '').toUpperCase();
  const [items, setItems] = useState<PaymentAccount[] | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [networks, setNetworks] = useState<string[]>([]);
  const [cashPickupNetworks, setCashPickupNetworks] = useState<string[]>(['Western Union', 'MoneyGram']);
  const [allowedKinds, setAllowedKinds] = useState<AccountKind[]>(() =>
    kindsForCountry(registrationCountry || '', role).kinds
  );
  const [modesHint, setModesHint] = useState<string | null>(null);

  // Modal state
  const [modalOpen, setModalOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<PaymentAccount | null>(null);
  const [formKind, setFormKind] = useState<AccountKind>('bank_iban');
  const [formData, setFormData] = useState<Partial<AccountPayload>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const fetch = useCallback(async () => {
    setLoading(true);
    setError(null);
    const res = await apiAccountsList(role);
    if (!res.success || !res.data) {
      setError(res.error || t('accounts.loadError'));
      setLoading(false);
      return;
    }
    setItems(res.data.items);
    setLoading(false);
  }, [role, t]);

  useEffect(() => { fetch(); }, [fetch]);

  useEffect(() => {
    apiCryptoNetworks().then(res => {
      if (res.success && res.data) setNetworks(res.data.networks);
    });
    apiCashPickupNetworks().then(res => {
      if (res.success && res.data?.networks?.length) {
        setCashPickupNetworks(res.data.networks.map(n => n.name));
      }
    });
  }, []);

  const applyModes = useCallback((country: string, message?: string | null) => {
    const local = kindsForCountry(country, role);
    setAllowedKinds(local.kinds);
    if (!country || country.length !== 2) {
      setModesHint('Complétez le pays d’enregistrement (Paramètres / KYC) pour adapter les modes de paiement.');
      return;
    }
    setModesHint(message ?? null);
  }, [role]);

  const loadPaymentModes = useCallback(async (country: string) => {
    // Miroir local d’abord (instantané, correct même si l’API est down).
    applyModes(country);
    const res = await apiFundingPaymentMethods(country || undefined);
    if (!res.success || !res.data) return;
    const apiCountry = (res.data.country || country || '').toUpperCase();
    const kinds = (res.data.account_kinds?.[role] ?? []) as AccountKind[];
    if (kinds.length > 0) {
      setAllowedKinds(kinds);
      setModesHint(res.data.message ?? null);
      return;
    }
    applyModes(apiCountry, res.data.message);
  }, [role, applyModes]);

  // Sources : pays d’enregistrement. Destinations : pays du formulaire ou enregistrement.
  useEffect(() => {
    if (role === 'source') {
      void loadPaymentModes(registrationCountry);
      return;
    }
    const c = (formData.country || registrationCountry || '').toUpperCase();
    void loadPaymentModes(c.length === 2 ? c : registrationCountry);
  }, [role, registrationCountry, formData.country, loadPaymentModes]);

  // Si le kind sélectionné n’est plus autorisé, bascule sur le premier dispo.
  useEffect(() => {
    if (!modalOpen || editTarget) return;
    if (allowedKinds.length > 0 && !allowedKinds.includes(formKind)) {
      setFormKind(allowedKinds[0]);
    }
  }, [allowedKinds, formKind, modalOpen, editTarget]);

  useEffect(() => {
    if (!modalOpen || formKind !== 'cash_pickup') return;
    if (!formData.operator) {
      setFormData(d => ({ ...d, operator: 'Western Union' }));
    }
  }, [formKind, modalOpen, formData.operator]);

  const kindChoices = allowedKinds;

  const openCreate = () => {
    if (role === 'source' && registrationCountry.length !== 2) {
      setFormError('Définissez d’abord votre pays d’enregistrement dans Paramètres.');
      setModalOpen(true);
      setEditTarget(null);
      setFormData({ role });
      return;
    }
    const country = role === 'source'
      ? registrationCountry
      : (registrationCountry || '');
    const { kinds, currency } = kindsForCountry(country, role);
    setEditTarget(null);
    setFormKind(kinds[0] ?? 'bank_iban');
    setAllowedKinds(kinds);
    setFormData({
      role,
      country: country || '',
      currency,
      label: '',
      holder_name: '',
    });
    setFormError(null);
    setModalOpen(true);
    if (country) void loadPaymentModes(country);
  };

  const openEdit = (account: PaymentAccount) => {
    setEditTarget(account);
    setFormKind(account.kind);
    setFormData({
      role: account.role,
      kind: account.kind,
      label: account.label,
      holder_name: account.holder_name ?? '',
      country: account.country ?? '',
      currency: account.currency ?? '',
      operator: account.operator ?? '',
      network: account.network ?? '',
      city: account.city ?? '',
      is_default: account.is_default,
    });
    setFormError(null);
    setModalOpen(true);
    if (account.country) void loadPaymentModes(account.country);
  };

  /** Validation côté client avant envoi. Retourne un message d'erreur ou null. */
  const validateClient = (): string | null => {
    if (!formData.label || formData.label.trim().length < 2) {
      return t('accounts.validation.label');
    }
    if (formKind === 'bank_iban' || formKind === 'virtual_iban') {
      const iban = (formData.iban ?? '').replace(/\s+/g, '').toUpperCase();
      if (!iban && !editTarget) return t('accounts.validation.iban');
      if (iban && iban.length < 15) return t('accounts.validation.ibanShort');
      if (iban && !/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/.test(iban)) return t('accounts.validation.ibanFormat');
    }
    if (formKind === 'mobile_money') {
      if (!formData.country && !editTarget) return t('accounts.validation.country');
      if (!formData.operator && !editTarget) return t('accounts.validation.operator');
      const phone = (formData.phone ?? '').replace(/\D/g, '');
      if (!phone && !editTarget) return t('accounts.validation.phone');
      if (phone && (phone.length < 8 || phone.length > 15)) return t('accounts.validation.phoneLength');
    }
    if (formKind === 'crypto_wallet') {
      if (!formData.network && !editTarget) return t('accounts.validation.network');
      const addr = (formData.address ?? '').trim();
      if (!addr && !editTarget) return t('accounts.validation.address');
      if (addr && addr.length < 10) return t('accounts.validation.addressShort');
    }
    if (formKind === 'card') {
      const pan = (formData.pan ?? '').replace(/\s/g, '');
      if (!pan && !editTarget) return t('accounts.validation.pan');
      if (pan && !/^[0-9]{13,19}$/.test(pan)) return t('accounts.validation.panLength');
      const expiry = (formData.expiry ?? '').trim();
      if (!expiry && !editTarget) return t('accounts.validation.expiry');
      if (expiry && !/^(0[1-9]|1[0-2])\/?([0-9]{2})$/.test(expiry)) return t('accounts.validation.expiryFormat');
    }
    if (formKind === 'cash_pickup') {
      if (!formData.operator && !editTarget) return t('accounts.validation.cashNetwork');
      if (!formData.city && !editTarget) return t('accounts.validation.city');
    }
    return null;
  };

  const submit = async () => {
    setSubmitting(true);
    setFormError(null);
    const clientError = validateClient();
    if (clientError) {
      setFormError(clientError);
      setSubmitting(false);
      return;
    }
    const payload: AccountPayload = {
      role,
      kind: formKind,
      label: formData.label ?? '',
      holder_name: formData.holder_name,
      country: formData.country,
      currency: formData.currency,
      operator: formData.operator,
      network: formData.network,
      city: formData.city,
      iban: formData.iban,
      bic: formData.bic,
      phone: formData.phone,
      pan: formData.pan,
      expiry: formData.expiry,
      address: formData.address,
      is_default: formData.is_default,
    };
    const res = editTarget
      ? await apiAccountsUpdate(editTarget.id, payload)
      : await apiAccountsCreate(payload);
    if (!res.success) {
      setFormError(res.error || t('accounts.saveError'));
      setSubmitting(false);
      return;
    }
    setModalOpen(false);
    setSubmitting(false);
    fetch();
  };

  const setDefault = async (id: number) => {
    await apiAccountsSetDefault(id);
    fetch();
  };

  const remove = async (id: number) => {
    if (!confirm(t('accounts.deleteConfirm'))) return;
    await apiAccountsDelete(id);
    fetch();
  };

  // --- États chargement / erreur -----------------------------------------
  if (loading || items === null) {
    return (
      <div className="g2" style={{ gap: 14 }}>
        {[0, 1, 2].map(i => (
          <div key={i} className="shimmer-bg" style={{ height: 80, borderRadius: 14 }} />
        ))}
      </div>
    );
  }

  if (error) {
    return (
      <div className="card card-hi-c" style={{ padding: 24, textAlign: 'center' }}>
        <p style={{ color: 'var(--text-mid)', marginBottom: 12 }}>{error}</p>
        <button className="btn btn-cyan" onClick={fetch}>{t('accounts.retry')}</button>
      </div>
    );
  }

  return (
    <>
      {/* Bouton d'ajout */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
        <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)' }}>
          {role === 'source'
            ? (items.length === 1 ? t('accounts.source.one', { n: items.length }) : t('accounts.source.many', { n: items.length }))
            : (items.length === 1 ? t('accounts.dest.one', { n: items.length }) : t('accounts.dest.many', { n: items.length }))}
        </div>
        <button className="btn btn-cyan" style={{ fontSize: 11 }} onClick={openCreate}>
          {role === 'source' ? t('accounts.addSource') : t('accounts.addDest')}
        </button>
      </div>

      {/* État vide */}
      {items.length === 0 && (
        <div className="card" style={{ padding: 40, textAlign: 'center' }}>
          <div style={{ fontSize: 32, marginBottom: 8 }}>
            {role === 'source' ? '💰' : '🎯'}
          </div>
          <div style={{ fontSize: 14, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 4 }}>
            {role === 'source' ? t('accounts.emptySource.title') : t('accounts.emptyDest.title')}
          </div>
          <p style={{ fontSize: 11, color: 'var(--text-mid)' }}>
            {role === 'source' ? t('accounts.emptySource.text') : t('accounts.emptyDest.text')}
          </p>
          <button className="btn btn-cyan" style={{ marginTop: 12, fontSize: 11 }} onClick={openCreate}>
            {t('accounts.first')}
          </button>
        </div>
      )}

      {/* Liste */}
      <div className="g2" style={{ gap: 12 }}>
        {items.map(account => {
          const meta = KIND_META[account.kind];
          return (
            <div
              key={account.id}
              className="card"
              style={{
                padding: 16,
                position: 'relative',
                overflow: 'hidden',
                borderColor: account.is_default ? 'rgba(0,207,160,0.3)' : undefined,
              }}
            >
              {/* Bandeau colorisé du haut */}
              <div style={{
                position: 'absolute', top: 0, left: 0, right: 0, height: 3,
                background: `linear-gradient(90deg, ${meta.color}, transparent)`,
              }} />

              {/* Ligne supérieure : icône + libellé + statut default */}
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 8 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                  <div className={`ib ${meta.ibCls}`} style={{ width: 36, height: 36, borderRadius: 9, fontSize: 16 }}>
                    {kindIcon(account.kind, role)}
                  </div>
                  <div>
                    <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)' }}>{account.label}</div>
                    <div style={{ fontSize: 9, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>
                      {t('accounts.kind.' + account.kind)}
                    </div>
                  </div>
                </div>
                {account.is_default && (
                  <div className="pill p-gr" style={{ fontSize: 7, flexShrink: 0 }}>{t('accounts.default')}</div>
                )}
              </div>

              {/* Détails masqués */}
              <div style={{ display: 'flex', flexDirection: 'column', gap: 4, marginBottom: 10, paddingBottom: 10, borderBottom: '1px solid var(--border-soft)' }}>
                {account.iban_masked && (
                  <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 10 }}>
                    <span style={{ color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>IBAN</span>
                    <span style={{ color: 'var(--text-bright)', fontFamily: 'var(--font-mono)', letterSpacing: '0.5px' }}>{account.iban_masked}</span>
                  </div>
                )}
                {account.phone_masked && (
                  <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 10 }}>
                    <span style={{ color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>
                      {account.operator ?? t('accounts.phone')}
                    </span>
                    <span style={{ color: 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>{account.phone_masked}</span>
                  </div>
                )}
                {account.address_masked && (
                  <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 10 }}>
                    <span style={{ color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>
                      {account.network ?? t('accounts.address')}
                    </span>
                    <span style={{ color: 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>{account.address_masked}</span>
                  </div>
                )}
                {account.pan_masked && (
                  <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 10 }}>
                    <span style={{ color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>
                      {t('accounts.card')} {account.expiry ? `(${account.expiry})` : ''}
                    </span>
                    <span style={{ color: 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>{account.pan_masked}</span>
                  </div>
                )}
                {account.city && (
                  <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 10 }}>
                    <span style={{ color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>{t('accounts.city')}</span>
                    <span style={{ color: 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>{account.city}</span>
                  </div>
                )}
                {account.holder_name && (
                  <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 10 }}>
                    <span style={{ color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>{t('accounts.holder')}</span>
                    <span style={{ color: 'var(--text-bright)' }}>{account.holder_name}</span>
                  </div>
                )}
              </div>

              {/* Statut de vérification + éligibilité au transfert */}
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginBottom: 10 }}>
                <span className={`pill ${
                  account.verification_status === 'verified' ? 'p-gr'
                    : account.verification_status === 'pending' ? 'p-g'
                    : account.verification_status === 'rejected' ? 'p-r' : 'p-v'
                }`} style={{ fontSize: 7.5 }}>
                  {account.verification_status === 'verified' ? t('accounts.verif.verified')
                    : account.verification_status === 'pending' ? t('accounts.verif.pending')
                    : account.verification_status === 'rejected' ? t('accounts.verif.rejected')
                    : t('accounts.verif.unverified')}
                </span>
                {role === 'source' && (
                  <span className={`pill ${account.supported_for_transfer ? 'p-gr' : 'p-g'}`} style={{ fontSize: 7.5 }}>
                    {account.supported_for_transfer ? t('accounts.transferOk') : t('accounts.transferBlocked')}
                  </span>
                )}
              </div>

              {/* Actions */}
              <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                {!account.is_default && (
                  <button
                    className="btn btn-ghost"
                    style={{ fontSize: 9, padding: '4px 8px' }}
                    onClick={() => setDefault(account.id)}
                  >
                    {t('accounts.setDefault')}
                  </button>
                )}
                <button
                  className="btn btn-ghost"
                  style={{ fontSize: 9, padding: '4px 8px' }}
                  onClick={() => openEdit(account)}
                >
                  {t('accounts.edit')}
                </button>
                <button
                  className="btn btn-ghost"
                  style={{ fontSize: 9, padding: '4px 8px', color: 'var(--red)', borderColor: 'rgba(255,69,96,0.3)' }}
                  onClick={() => remove(account.id)}
                >
                  {t('accounts.delete')}
                </button>
              </div>
            </div>
          );
        })}
      </div>

      {/* Modal de configuration */}
      {modalOpen && (
        <div
          style={{
            position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.6)',
            backdropFilter: 'blur(8px)', display: 'flex', alignItems: 'center', justifyContent: 'center',
            zIndex: 200, padding: 20,
          }}
          onClick={() => setModalOpen(false)}
        >
          <div
            className="card card-hi-c animate-fade"
            style={{ width: '100%', maxWidth: 520, padding: 24, maxHeight: '85vh', overflowY: 'auto' }}
            onClick={e => e.stopPropagation()}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
              <div>
                <div style={{ fontSize: 9, color: 'var(--text-dim)', letterSpacing: '0.15em', textTransform: 'uppercase', fontFamily: 'var(--font-mono)' }}>
                  {editTarget ? t('accounts.modal.edit') : t('accounts.modal.new')}
                </div>
                <div style={{ fontSize: 16, fontWeight: 700, color: 'var(--text-bright)' }}>
                  {role === 'source' ? t('accounts.modal.source') : t('accounts.modal.destination')}
                </div>
              </div>
              <button className="btn btn-ghost" style={{ fontSize: 11 }} onClick={() => setModalOpen(false)}>
                ✕
              </button>
            </div>

            {/* Type de compte */}
            {!editTarget && (
              <div style={{ marginBottom: 14 }}>
                <div style={{ fontSize: 9, color: 'var(--text-dim)', letterSpacing: '0.1em', textTransform: 'uppercase', fontFamily: 'var(--font-mono)', marginBottom: 6 }}>
                  {t('accounts.modal.type')}
                </div>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 6 }}>
                  {kindChoices.map(k => {
                    const m = KIND_META[k];
                    return (
                      <button
                        key={k}
                        type="button"
                        className={`pill ${formKind === k ? m.pillCls : ''}`}
                        style={{
                          fontSize: 8, padding: '6px 4px', cursor: 'pointer',
                          background: formKind === k ? `rgba(0,200,255,0.1)` : 'var(--panel2)',
                          borderColor: formKind === k ? 'rgba(0,200,255,0.35)' : 'var(--border)',
                          color: formKind === k ? 'var(--cyan)' : 'var(--text-dim)',
                          display: 'flex', flexDirection: 'column', gap: 3,
                        }}
                        onClick={() => setFormKind(k)}
                      >
                        <span style={{ fontSize: 18 }}>{kindIcon(k, role)}</span>
                        {t('accounts.kind.' + k)}
                      </button>
                    );
                  })}
                </div>
                {kindChoices.length === 0 && (
                  <div style={{ fontSize: 11, color: 'var(--text-dim)', marginTop: 8 }}>
                    Aucun mode disponible pour ce pays. Vérifiez le pays d’enregistrement (KYC).
                  </div>
                )}
                {modesHint && (
                  <div style={{ fontSize: 11, color: 'var(--gold)', marginTop: 8 }}>{modesHint}</div>
                )}
              </div>
            )}

            {/* Champs communs */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginBottom: 14 }}>
              <FormInput label={t('accounts.label')} value={formData.label ?? ''} onChange={v => setFormData(d => ({ ...d, label: v }))} placeholder={t('accounts.labelPlaceholder')} />

              {/* Pays + devise */}
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
                <div>
                  <FormLabel>{t('accounts.country')}</FormLabel>
                  <select
                    value={formData.country ?? ''}
                    onChange={e => {
                      const next = e.target.value;
                      setFormData(d => ({
                        ...d,
                        country: next,
                        operator: '',
                      }));
                    }}
                    disabled={role === 'source' && !!registrationCountry && !editTarget}
                    style={selectStyle}
                  >
                    <option value="">—</option>
                  {countries.map(c => (
                    <option key={c.code} value={c.code}>{c.name}</option>
                  ))}
                  </select>
                  {role === 'source' && registrationCountry && !editTarget && (
                    <div style={{ fontSize: 9, color: 'var(--text-dim)', marginTop: 4 }}>
                      Aligné sur le pays de création du compte ({registrationCountry}).
                    </div>
                  )}
                </div>
                <div>
                  <FormLabel>{t('accounts.currency')}</FormLabel>
                  <select
                    value={formData.currency ?? ''}
                    onChange={e => setFormData(d => ({ ...d, currency: e.target.value }))}
                    style={selectStyle}
                  >
                    {['EUR','USD','GBP','XAF','XOF','NGN','KES','GHS','ZAR','USDT','USDC'].map(c => (
                      <option key={c} value={c}>{c}</option>
                    ))}
                  </select>
                </div>
              </div>

              <FormInput label={t('accounts.holderLabel')} value={formData.holder_name ?? ''} onChange={v => setFormData(d => ({ ...d, holder_name: v }))} placeholder={t('accounts.holderPlaceholder')} />
            </div>

            {/* Champs spécifiques au kind */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginBottom: 14 }}>
              {(formKind === 'bank_iban' || formKind === 'virtual_iban') && (
                <>
                  <FormInput
                    label={t('accounts.iban', { keep: editTarget ? t('accounts.keep') : '' })}
                    value={formData.iban ?? ''}
                    onChange={v => setFormData(d => ({ ...d, iban: v }))}
                    placeholder={editTarget ? t('accounts.keepValue') : t('accounts.ibanPlaceholder')}
                    mono
                  />
                  <FormInput label={t('accounts.bic')} value={formData.bic ?? ''} onChange={v => setFormData(d => ({ ...d, bic: v }))} placeholder="SwanFRPP" />
                </>
              )}

              {formKind === 'mobile_money' && (
                <>
                  <div>
                    <FormLabel>{t('accounts.operator')}</FormLabel>
                    <select
                      value={formData.operator ?? ''}
                      onChange={e => setFormData(d => ({ ...d, operator: e.target.value }))}
                      style={selectStyle}
                    >
                      <option value="">—</option>
                      {getOperatorsForCountry(formData.country ?? '').map(op => (
                        <option key={op.name} value={op.name}>{op.name}</option>
                      ))}
                    </select>
                  </div>
                  <FormInput
                    label={t('accounts.mobileNumber', { keep: editTarget ? t('accounts.keep') : '' })}
                    value={formData.phone ?? ''}
                    onChange={v => setFormData(d => ({ ...d, phone: v.replace(/\D/g, '') }))}
                    placeholder={editTarget ? t('accounts.keepNumber') : t('accounts.phonePlaceholder')}
                    mono
                  />
                </>
              )}

              {formKind === 'crypto_wallet' && (
                <>
                  <div>
                    <FormLabel>{t('accounts.network')}</FormLabel>
                    <select
                      value={formData.network ?? ''}
                      onChange={e => setFormData(d => ({ ...d, network: e.target.value }))}
                      style={selectStyle}
                    >
                      <option value="">—</option>
                      {networks.map(n => (
                        <option key={n} value={n}>{n}</option>
                      ))}
                    </select>
                  </div>
                  <FormInput
                    label={t('accounts.walletAddress', { keep: editTarget ? t('accounts.keep') : '' })}
                    value={formData.address ?? ''}
                    onChange={v => setFormData(d => ({ ...d, address: v }))}
                    placeholder={editTarget ? t('accounts.keepAddress') : t('accounts.walletPlaceholder')}
                    mono
                  />
                </>
              )}

              {formKind === 'card' && (
                <>
                  <FormInput
                    label={t('accounts.pan', { keep: editTarget ? t('accounts.keep') : '' })}
                    value={formData.pan ?? ''}
                    onChange={v => setFormData(d => ({ ...d, pan: v.replace(/\s/g, '') }))}
                    placeholder={editTarget ? '••••••••••••' : t('accounts.panPlaceholder')}
                    mono
                  />
                  <FormInput
                    label={t('accounts.expiry')}
                    value={formData.expiry ?? ''}
                    onChange={v => setFormData(d => ({ ...d, expiry: v }))}
                    placeholder="MM/AA"
                  />
                </>
              )}

              {formKind === 'cash_pickup' && (
                <>
                  <div>
                    <FormLabel>{t('accounts.cashNetwork')}</FormLabel>
                    <select
                      value={formData.operator ?? 'Western Union'}
                      onChange={e => setFormData(d => ({ ...d, operator: e.target.value }))}
                      style={selectStyle}
                    >
                      {cashPickupNetworks.map(n => (
                        <option key={n} value={n}>{n}</option>
                      ))}
                    </select>
                  </div>
                  <FormInput
                    label={t('accounts.cityPickup')}
                    value={formData.city ?? ''}
                    onChange={v => setFormData(d => ({ ...d, city: v }))}
                    placeholder={t('accounts.cityPlaceholder')}
                  />
                </>
              )}
            </div>

            {/* Par défaut */}
            <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 11, color: 'var(--text-mid)', marginBottom: 14, cursor: 'pointer' }}>
              <input
                type="checkbox"
                checked={!!formData.is_default}
                onChange={e => setFormData(d => ({ ...d, is_default: e.target.checked }))}
                style={{ accentColor: 'var(--cyan)' }}
              />
              {t('accounts.setDefaultLabel')}
            </label>

            {formError && (
              <div style={{
                background: 'rgba(255,69,96,0.08)', border: '1px solid rgba(255,69,96,0.3)',
                borderRadius: 8, padding: 10, marginBottom: 12, fontSize: 11, color: 'var(--red)',
              }}>
                {formError}
              </div>
            )}

            <div style={{ display: 'flex', gap: 8 }}>
              <button className="btn btn-cyan" style={{ flex: 1, justifyContent: 'center', fontSize: 12 }} onClick={submit} disabled={submitting}>
                {submitting ? t('accounts.saving') : (editTarget ? t('accounts.update') : t('accounts.save'))}
              </button>
              <button className="btn btn-ghost" style={{ fontSize: 12 }} onClick={() => setModalOpen(false)}>
                {t('accounts.cancel')}
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

function FormLabel({ children }: { children: React.ReactNode }) {
  return (
    <div style={{
      fontSize: 9, color: 'var(--text-dim)', letterSpacing: '0.1em',
      textTransform: 'uppercase', fontFamily: 'var(--font-mono)', marginBottom: 4,
    }}>
      {children}
    </div>
  );
}

function FormInput({
  label, value, onChange, placeholder, mono,
}: { label: string; value: string; onChange: (v: string) => void; placeholder?: string; mono?: boolean }) {
  return (
    <div>
      <FormLabel>{label}</FormLabel>
      <input
        type="text"
        value={value}
        onChange={e => onChange(e.target.value)}
        placeholder={placeholder}
        style={{
          width: '100%', padding: '8px 10px', background: 'var(--panel2)',
          border: '1px solid var(--border)', borderRadius: 8,
          color: 'var(--text-bright)', fontSize: 12,
          fontFamily: mono ? 'var(--font-mono)' : 'inherit',
          outline: 'none',
        }}
      />
    </div>
  );
}

const selectStyle: React.CSSProperties = {
  width: '100%', padding: '8px 10px', background: 'var(--panel2)',
  border: '1px solid var(--border)', borderRadius: 8,
  color: 'var(--text-bright)', fontSize: 12, outline: 'none',
};
