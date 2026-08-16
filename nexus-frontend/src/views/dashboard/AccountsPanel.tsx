import { useState, useEffect, useCallback } from 'react';
import {
  apiAccountsList,
  apiAccountsCreate,
  apiAccountsUpdate,
  apiAccountsDelete,
  apiAccountsSetDefault,
  apiCryptoNetworks,
  type PaymentAccount,
  type AccountKind,
  type AccountPayload,
} from '../../api/client';
import { countries } from '../../data/countries';
import { getOperatorsForCountry } from '../../data/mobile-money';

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

/**
 * AccountsPanel — CRUD des sources de financement / destinations.
 *
 * - Liste les comptes de l'utilisateur (filtrés par rôle).
 * - Création / édition via modal avec validation par kind.
 * - Définit un compte par défaut et le supprime (avec promotion du suivant).
 * - Toutes les données sensibles (IBAN, PAN, téléphone, adresse) sont
 *   chiffrées côté serveur et masquées à l'affichage.
 */
export default function AccountsPanel({ role }: AccountsPanelProps) {
  const [items, setItems] = useState<PaymentAccount[] | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [networks, setNetworks] = useState<string[]>([]);

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
      setError(res.error || 'Erreur lors du chargement des comptes.');
      setLoading(false);
      return;
    }
    setItems(res.data.items);
    setLoading(false);
  }, [role]);

  useEffect(() => { fetch(); }, [fetch]);

  useEffect(() => {
    apiCryptoNetworks().then(res => {
      if (res.success && res.data) setNetworks(res.data.networks);
    });
  }, []);

  const openCreate = () => {
    setEditTarget(null);
    setFormKind('bank_iban');
    setFormData({ role, country: 'FR', currency: 'EUR', label: '', holder_name: '' });
    setFormError(null);
    setModalOpen(true);
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
  };

  /** Validation côté client avant envoi. Retourne un message d'erreur ou null. */
  const validateClient = (): string | null => {
    if (!formData.label || formData.label.trim().length < 2) {
      return 'Le libellé est requis (2 caractères minimum).';
    }
    if (formKind === 'bank_iban' || formKind === 'virtual_iban') {
      const iban = (formData.iban ?? '').replace(/\s+/g, '').toUpperCase();
      if (!iban && !editTarget) return 'L\'IBAN est requis.';
      if (iban && iban.length < 15) return 'L\'IBAN est trop court (15 caractères minimum).';
      if (iban && !/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/.test(iban)) return 'Format IBAN invalide.';
    }
    if (formKind === 'mobile_money') {
      if (!formData.country && !editTarget) return 'Le pays est requis pour Mobile Money.';
      if (!formData.operator && !editTarget) return 'L\'opérateur est requis.';
      const phone = (formData.phone ?? '').replace(/\D/g, '');
      if (!phone && !editTarget) return 'Le numéro de mobile est requis.';
      if (phone && (phone.length < 8 || phone.length > 15)) return 'Le numéro doit contenir entre 8 et 15 chiffres.';
    }
    if (formKind === 'crypto_wallet') {
      if (!formData.network && !editTarget) return 'Le réseau blockchain est requis.';
      const addr = (formData.address ?? '').trim();
      if (!addr && !editTarget) return 'L\'adresse du wallet est requise.';
      if (addr && addr.length < 10) return 'L\'adresse semble trop courte.';
    }
    if (formKind === 'card') {
      const pan = (formData.pan ?? '').replace(/\s/g, '');
      if (!pan && !editTarget) return 'Le numéro de carte est requis.';
      if (pan && !/^[0-9]{13,19}$/.test(pan)) return 'Le numéro doit contenir entre 13 et 19 chiffres.';
      const expiry = (formData.expiry ?? '').trim();
      if (!expiry && !editTarget) return 'La date d\'expiration est requise.';
      if (expiry && !/^(0[1-9]|1[0-2])\/?([0-9]{2})$/.test(expiry)) return 'Format d\'expiration invalide (MM/AA).';
    }
    if (formKind === 'cash_pickup') {
      if (!formData.city && !editTarget) return 'La ville est requise pour un cash pickup.';
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
      setFormError(res.error || 'Erreur lors de l\'enregistrement.');
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
    if (!confirm('Supprimer ce compte ?')) return;
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
        <button className="btn btn-cyan" onClick={fetch}>↻ Réessayer</button>
      </div>
    );
  }

  return (
    <>
      {/* Bouton d'ajout */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
        <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)' }}>
          {items.length} {role === 'source' ? 'source' : 'destination'}{items.length > 1 ? 's' : ''}
        </div>
        <button className="btn btn-cyan" style={{ fontSize: 11 }} onClick={openCreate}>
          + Ajouter {role === 'source' ? 'une source' : 'une destination'}
        </button>
      </div>

      {/* État vide */}
      {items.length === 0 && (
        <div className="card" style={{ padding: 40, textAlign: 'center' }}>
          <div style={{ fontSize: 32, marginBottom: 8 }}>
            {role === 'source' ? '💰' : '🎯'}
          </div>
          <div style={{ fontSize: 14, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 4 }}>
            Aucun {role === 'source' ? 'compte source' : 'compte destination'} configuré
          </div>
          <p style={{ fontSize: 11, color: 'var(--text-mid)' }}>
            {role === 'source'
              ? 'Ajoutez votre premier compte pour pouvoir envoyer de l\'argent.'
              : 'Ajoutez votre premier compte pour pouvoir recevoir de l\'argent.'}
          </p>
          <button className="btn btn-cyan" style={{ marginTop: 12, fontSize: 11 }} onClick={openCreate}>
            + Configurer mon premier compte
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
                      {meta.label}
                    </div>
                  </div>
                </div>
                {account.is_default && (
                  <div className="pill p-gr" style={{ fontSize: 7, flexShrink: 0 }}>✓ Défaut</div>
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
                      {account.operator ?? 'Téléphone'}
                    </span>
                    <span style={{ color: 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>{account.phone_masked}</span>
                  </div>
                )}
                {account.address_masked && (
                  <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 10 }}>
                    <span style={{ color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>
                      {account.network ?? 'Adresse'}
                    </span>
                    <span style={{ color: 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>{account.address_masked}</span>
                  </div>
                )}
                {account.pan_masked && (
                  <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 10 }}>
                    <span style={{ color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>
                      Carte {account.expiry ? `(${account.expiry})` : ''}
                    </span>
                    <span style={{ color: 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>{account.pan_masked}</span>
                  </div>
                )}
                {account.city && (
                  <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 10 }}>
                    <span style={{ color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>Ville</span>
                    <span style={{ color: 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>{account.city}</span>
                  </div>
                )}
                {account.holder_name && (
                  <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 10 }}>
                    <span style={{ color: 'var(--text-dim)', fontFamily: 'var(--font-mono)' }}>Titulaire</span>
                    <span style={{ color: 'var(--text-bright)' }}>{account.holder_name}</span>
                  </div>
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
                    Par défaut
                  </button>
                )}
                <button
                  className="btn btn-ghost"
                  style={{ fontSize: 9, padding: '4px 8px' }}
                  onClick={() => openEdit(account)}
                >
                  Modifier
                </button>
                <button
                  className="btn btn-ghost"
                  style={{ fontSize: 9, padding: '4px 8px', color: 'var(--red)', borderColor: 'rgba(255,69,96,0.3)' }}
                  onClick={() => remove(account.id)}
                >
                  Supprimer
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
                  {editTarget ? 'Modifier' : 'Nouveau'} compte
                </div>
                <div style={{ fontSize: 16, fontWeight: 700, color: 'var(--text-bright)' }}>
                  {role === 'source' ? 'Source de financement' : 'Destination'}
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
                  Type
                </div>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 6 }}>
                  {(role === 'source'
                    ? ['bank_iban', 'mobile_money', 'crypto_wallet', 'card', 'virtual_iban'] as AccountKind[]
                    : ['bank_iban', 'mobile_money', 'crypto_wallet', 'cash_pickup'] as AccountKind[]
                  ).map(k => {
                    const m = KIND_META[k];
                    return (
                      <button
                        key={k}
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
                        {m.label}
                      </button>
                    );
                  })}
                </div>
              </div>
            )}

            {/* Champs communs */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginBottom: 14 }}>
              <FormInput label="Libellé" value={formData.label ?? ''} onChange={v => setFormData(d => ({ ...d, label: v }))} placeholder="Ex: Compte courant Swan" />

              {/* Pays + devise */}
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
                <div>
                  <FormLabel>Pays</FormLabel>
                  <select
                    value={formData.country ?? ''}
                    onChange={e => setFormData(d => ({ ...d, country: e.target.value }))}
                    style={selectStyle}
                  >
                    <option value="">—</option>
                  {countries.map(c => (
                    <option key={c.code} value={c.code}>{c.name}</option>
                  ))}
                  </select>
                </div>
                <div>
                  <FormLabel>Devise</FormLabel>
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

              <FormInput label="Titulaire" value={formData.holder_name ?? ''} onChange={v => setFormData(d => ({ ...d, holder_name: v }))} placeholder="Nom du titulaire" />
            </div>

            {/* Champs spécifiques au kind */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginBottom: 14 }}>
              {(formKind === 'bank_iban' || formKind === 'virtual_iban') && (
                <>
                  <FormInput
                    label={`IBAN ${editTarget ? '(laisser vide pour conserver)' : ''}`}
                    value={formData.iban ?? ''}
                    onChange={v => setFormData(d => ({ ...d, iban: v }))}
                    placeholder={editTarget ? '•••• conserver la valeur existante ••••' : 'FR76 3000 4000 0123 4567 8910 123'}
                    mono
                  />
                  <FormInput label="BIC (optionnel)" value={formData.bic ?? ''} onChange={v => setFormData(d => ({ ...d, bic: v }))} placeholder="SwanFRPP" />
                </>
              )}

              {formKind === 'mobile_money' && (
                <>
                  <div>
                    <FormLabel>Opérateur</FormLabel>
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
                    label={`Numéro de mobile ${editTarget ? '(laisser vide pour conserver)' : ''}`}
                    value={formData.phone ?? ''}
                    onChange={v => setFormData(d => ({ ...d, phone: v.replace(/\D/g, '') }))}
                    placeholder={editTarget ? '•••• conserver le numéro existant ••••' : '242066123456'}
                    mono
                  />
                </>
              )}

              {formKind === 'crypto_wallet' && (
                <>
                  <div>
                    <FormLabel>Réseau blockchain</FormLabel>
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
                    label={`Adresse du wallet ${editTarget ? '(laisser vide pour conserver)' : ''}`}
                    value={formData.address ?? ''}
                    onChange={v => setFormData(d => ({ ...d, address: v }))}
                    placeholder={editTarget ? '•••• conserver l\'adresse existante ••••' : '0x1234… ou bc1…'}
                    mono
                  />
                </>
              )}

              {formKind === 'card' && (
                <>
                  <FormInput
                    label={`PAN ${editTarget ? '(laisser vide pour conserver)' : ''}`}
                    value={formData.pan ?? ''}
                    onChange={v => setFormData(d => ({ ...d, pan: v.replace(/\s/g, '') }))}
                    placeholder={editTarget ? '••••••••••••' : '4242 4242 4242 4242'}
                    mono
                  />
                  <FormInput
                    label="Date d'expiration"
                    value={formData.expiry ?? ''}
                    onChange={v => setFormData(d => ({ ...d, expiry: v }))}
                    placeholder="MM/AA"
                  />
                </>
              )}

              {formKind === 'cash_pickup' && (
                <FormInput
                  label="Ville (cash pickup)"
                  value={formData.city ?? ''}
                  onChange={v => setFormData(d => ({ ...d, city: v }))}
                  placeholder="Brazzaville, Kinshasa, Dakar…"
                />
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
              Définir comme compte par défaut pour ce rôle
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
                {submitting ? 'Enregistrement...' : (editTarget ? '✓ Mettre à jour' : '✓ Enregistrer')}
              </button>
              <button className="btn btn-ghost" style={{ fontSize: 12 }} onClick={() => setModalOpen(false)}>
                Annuler
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
