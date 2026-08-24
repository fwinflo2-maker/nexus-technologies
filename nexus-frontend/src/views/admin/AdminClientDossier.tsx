import { useEffect, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { AnimatePresence, motion } from 'framer-motion';
import { EASE } from '../../components/anim/Premium';
import type { ControlAccount, ControlClientDetail } from '../../api/client';
import './AdminClientDossier.css';

const COUNTRY_NAME: Record<string, string> = {
  FR: 'France', CG: 'Congo', CD: 'RDC', CM: 'Cameroun', GA: 'Gabon',
  SN: 'Sénégal', CI: "Côte d'Ivoire", TG: 'Togo', BJ: 'Bénin', BF: 'Burkina Faso',
  ML: 'Mali', NE: 'Niger', TD: 'Tchad', MA: 'Maroc', DZ: 'Algérie', TN: 'Tunisie',
  US: 'États-Unis', GB: 'Royaume-Uni', DE: 'Allemagne', BE: 'Belgique', CH: 'Suisse',
  ES: 'Espagne', IT: 'Italie', PT: 'Portugal', NL: 'Pays-Bas', CA: 'Canada',
  NG: 'Nigéria', KE: 'Kenya', GH: 'Ghana', ZA: 'Afrique du Sud',
};

function countryLabel(code: string | null): string {
  return (code && COUNTRY_NAME[code]) || code || '—';
}

function money(v: string | number, cur: string): string {
  const n = Number(v);
  if (!isFinite(n) || n === 0) return `0 ${cur}`;
  return `${n.toLocaleString('fr-FR')} ${cur}`;
}

function fmtTxAmount(tx: Record<string, unknown>): string {
  const cur = String(tx.currency ?? '');
  const amt = String(tx.amount ?? '0');
  const n = Number(amt);
  const label = /^[A-Z]{3}$/.test(cur) ? cur : cur;
  if (!isFinite(n)) return `${amt} ${label}`;
  return `${n.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${label}`;
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return 'NX';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function statusTone(status: string): 'ok' | 'warn' | 'bad' {
  if (status === 'ACTIVE') return 'ok';
  if (status === 'SUSPENDED' || status === 'CLOSED') return 'bad';
  return 'warn';
}

function statusLabel(status: string): string {
  if (status === 'ACTIVE') return 'Actif';
  if (status === 'PENDING') return 'En attente';
  if (status === 'SUSPENDED') return 'Suspendu';
  if (status === 'CLOSED') return 'Clôturé';
  return status;
}

function kycLabel(level: string): string {
  if (!level || level === 'none') return 'KYC non vérifié';
  return `KYC ${level}`;
}

type TabId = 'identite' | 'soldes' | 'moyens' | 'journal';
type StatusAction = 'ACTIVE' | 'SUSPENDED' | 'CLOSED';
export type DossierSection = 'compliance' | 'support' | 'audit' | 'linked-accounts' | 'transactions';

const TABS: Array<{ id: TabId; label: string }> = [
  { id: 'identite', label: 'Identité' },
  { id: 'soldes', label: 'Soldes' },
  { id: 'moyens', label: 'Moyens' },
  { id: 'journal', label: 'Journal' },
];

async function copyText(value: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(value);
    return true;
  } catch {
    return false;
  }
}

async function runPdfExport(
  action: 'dossier' | 'statement' | 'print',
  client: ControlClientDetail,
  fileId: string,
  setBusy: (v: boolean) => void,
  onDone: () => void,
  onError: (msg: string) => void,
): Promise<void> {
  setBusy(true);
  try {
    const pdf = await import('../../lib/adminDossierPdf');
    if (action === 'dossier') await pdf.downloadClientDossierPdf(client, fileId);
    else if (action === 'statement') await pdf.downloadTransactionsStatementPdf(client, fileId);
    else await pdf.printTransactionsStatement(client, fileId);
    onDone();
  } catch (err) {
    console.error(err);
    onError('Export PDF impossible. Rechargez la page puis réessayez.');
  } finally {
    setBusy(false);
  }
}

function exportDossierPdf(
  client: ControlClientDetail,
  fileId: string,
  setBusy: (v: boolean) => void,
  onDone: () => void,
  onError: (msg: string) => void,
): void {
  void runPdfExport('dossier', client, fileId, setBusy, onDone, onError);
}

function exportStatementPdf(
  client: ControlClientDetail,
  fileId: string,
  setBusy: (v: boolean) => void,
  onDone: () => void,
  onError: (msg: string) => void,
): void {
  void runPdfExport('statement', client, fileId, setBusy, onDone, onError);
}

function printStatement(
  client: ControlClientDetail,
  fileId: string,
  setBusy: (v: boolean) => void,
  onDone: () => void,
  onError: (msg: string) => void,
): void {
  void runPdfExport('print', client, fileId, setBusy, onDone, onError);
}

export default function AdminClientDossier({
  client,
  loading,
  actionError,
  onStatus,
  onOpenSection,
  onClose,
}: {
  client: ControlClientDetail | null;
  loading: boolean;
  actionError: string;
  onStatus: (status: StatusAction, reason: string) => void;
  onOpenSection?: (section: DossierSection) => void;
  onClose: () => void;
}) {
  const [tab, setTab] = useState<TabId>('identite');
  const [reason, setReason] = useState('');
  const [pending, setPending] = useState<Extract<StatusAction, 'SUSPENDED' | 'CLOSED'> | null>(null);
  const [copied, setCopied] = useState('');
  const [pdfBusy, setPdfBusy] = useState(false);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    window.addEventListener('keydown', onKey);
    return () => {
      document.body.style.overflow = prev;
      window.removeEventListener('keydown', onKey);
    };
  }, [onClose]);

  useEffect(() => {
    setTab('identite');
    setReason('');
    setPending(null);
    setCopied('');
  }, [client?.id]);

  useEffect(() => {
    setPending(null);
    setReason('');
  }, [client?.status]);

  const isBusiness = client?.account_type === 'business';
  const fileId = client ? `NX-${String(client.id).padStart(6, '0')}` : 'NX-······';

  const markCopied = (label: string) => {
    setCopied(label);
    window.setTimeout(() => setCopied(''), 1600);
  };

  const go = (section: DossierSection) => {
    onOpenSection?.(section);
    onClose();
  };

  const overlay = (
    <AnimatePresence>
      <motion.div
        className="nx-dossier-scrim"
        role="dialog"
        aria-modal="true"
        aria-labelledby="nx-dossier-title"
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        exit={{ opacity: 0 }}
        transition={{ duration: 0.22, ease: EASE }}
        onClick={onClose}
      >
        <motion.article
          className="nx-dossier"
          initial={{ opacity: 0, y: 12, scale: 0.99 }}
          animate={{ opacity: 1, y: 0, scale: 1 }}
          exit={{ opacity: 0, y: 8, scale: 0.99 }}
          transition={{ duration: 0.28, ease: EASE }}
          onClick={(e) => e.stopPropagation()}
        >

          <header className="nx-dossier-head">
            <div className="nx-dossier-head-meta">
              <span className="nx-dossier-file">{fileId}</span>
              <span className="nx-dossier-head-label">Dossier client</span>
            </div>
            <div className="nx-dossier-tabs" role="tablist" aria-label="Sections du dossier">
              {TABS.map((item) => (
                <button
                  key={item.id}
                  type="button"
                  role="tab"
                  className="nx-dossier-tab"
                  aria-selected={tab === item.id}
                  onClick={() => setTab(item.id)}
                >
                  {item.label}
                </button>
              ))}
            </div>
            <button type="button" className="nx-dossier-close" onClick={onClose} aria-label="Fermer le dossier">
              Fermer ✕
            </button>
          </header>

          <aside className="nx-dossier-rail">
            {loading || !client ? (
              <div className="nx-dossier-loading"><div className="nexus-spinner" />Ouverture du dossier…</div>
            ) : (
              <>
                <div className="nx-dossier-mono">
                  {client.avatar
                    ? <img src={client.avatar} alt="" />
                    : initials(client.full_name)}
                </div>
                <h2 id="nx-dossier-title" className="nx-dossier-name">{client.full_name}</h2>
                <div className="nx-dossier-meta">
                  {client.email}
                  {client.phone ? <><br />{client.phone}</> : null}
                </div>
                <div className="nx-dossier-pills">
                  <span className={`nx-dossier-pill is-${statusTone(client.status)}`}>{statusLabel(client.status)}</span>
                  <span className="nx-dossier-pill">{kycLabel(client.kyc_level)}</span>
                  <span className="nx-dossier-pill">{isBusiness ? 'Entreprise' : 'Personnel'}</span>
                </div>
                <div className="nx-dossier-kpis">
                  <div className="nx-dossier-kpi"><span>EUR</span><strong>{money(client.balances.EUR, 'EUR')}</strong></div>
                  <div className="nx-dossier-kpi"><span>Moyens</span><strong>{client.accounts.length}</strong></div>
                  <div className="nx-dossier-kpi"><span>Opérations</span><strong>{client.transactions.length}</strong></div>
                </div>
              </>
            )}
          </aside>

          <div className="nx-dossier-main">
            <div className="nx-dossier-panel">
              {loading || !client ? (
                <div className="nx-dossier-empty">Chargement des pièces du dossier…</div>
              ) : (
                <TabBody
                  tab={tab}
                  client={client}
                  isBusiness={Boolean(isBusiness)}
                  fileId={fileId}
                  pdfBusy={pdfBusy}
                  onPrintStatement={() => printStatement(client, fileId, setPdfBusy, () => markCopied('Impression lancée'), markCopied)}
                  onExportStatement={() => exportStatementPdf(client, fileId, setPdfBusy, () => markCopied('Relevé PDF téléchargé'), markCopied)}
                />
              )}
            </div>
            <div className="nx-dossier-mrz" aria-hidden="true">
              NX&lt;&lt;CLIENT&lt;&lt;{fileId}&lt;&lt;{(client?.account_type ?? '—').toUpperCase()}&lt;&lt;{client?.status ?? '…'}
            </div>
          </div>

          {client && !loading ? (
            <div className="nx-dossier-ops">
              <div className="nx-dossier-ops-row">
                <span className="nx-dossier-ops-label">Dossier</span>
                <button type="button" className="nx-dossier-btn" onClick={() => { void copyText(fileId).then((ok) => ok && markCopied('Identifiant copié')); }}>
                  Copier l’ID
                </button>
                <button type="button" className="nx-dossier-btn" onClick={() => { void copyText(client.email).then((ok) => ok && markCopied('E-mail copié')); }}>
                  Copier l’e-mail
                </button>
                {client.phone ? (
                  <button type="button" className="nx-dossier-btn" onClick={() => { void copyText(client.phone ?? '').then((ok) => ok && markCopied('Téléphone copié')); }}>
                    Copier le tél.
                  </button>
                ) : null}
                <button
                  type="button"
                  className="nx-dossier-btn"
                  disabled={pdfBusy}
                  onClick={() => exportDossierPdf(client, fileId, setPdfBusy, () => markCopied('Dossier PDF téléchargé'), markCopied)}
                >
                  Exporter dossier PDF
                </button>
                <button
                  type="button"
                  className="nx-dossier-btn"
                  disabled={pdfBusy}
                  onClick={() => exportStatementPdf(client, fileId, setPdfBusy, () => markCopied('Relevé PDF téléchargé'), markCopied)}
                >
                  Relevé PDF
                </button>
                <a className="nx-dossier-btn" href={`mailto:${encodeURIComponent(client.email)}?subject=${encodeURIComponent(`Nexus ${fileId}`)}`}>
                  Contacter
                </a>
                {copied ? <span className="nx-dossier-toast" role="status">{copied}</span> : null}
              </div>

              <div className="nx-dossier-ops-row">
                <span className="nx-dossier-ops-label">Ouvrir</span>
                <button type="button" className="nx-dossier-btn" onClick={() => go('compliance')}>KYC / Compliance</button>
                <button type="button" className="nx-dossier-btn" onClick={() => go('transactions')}>Transactions</button>
                <button type="button" className="nx-dossier-btn" onClick={() => go('support')}>Support</button>
                <button type="button" className="nx-dossier-btn" onClick={() => go('audit')}>Audit</button>
                <button type="button" className="nx-dossier-btn" onClick={() => go('linked-accounts')}>Comptes liés</button>
              </div>

              <div className="nx-dossier-ops-row">
                <span className="nx-dossier-ops-label">Contrôle</span>
                {pending ? (
                  <>
                    <textarea
                      className="nx-dossier-reason"
                      value={reason}
                      onChange={(e) => setReason(e.target.value)}
                      placeholder={pending === 'CLOSED' ? 'Motif de clôture (obligatoire)' : 'Motif de suspension (obligatoire)'}
                      autoFocus
                    />
                    <button
                      type="button"
                      className="nx-dossier-btn is-danger"
                      disabled={reason.trim() === ''}
                      onClick={() => onStatus(pending, reason.trim())}
                    >
                      {pending === 'CLOSED' ? 'Confirmer la clôture' : 'Confirmer la suspension'}
                    </button>
                    <button type="button" className="nx-dossier-btn" onClick={() => { setPending(null); setReason(''); }}>
                      Annuler
                    </button>
                  </>
                ) : (
                  <>
                    {client.status === 'SUSPENDED' || client.status === 'CLOSED' ? (
                      <button type="button" className="nx-dossier-btn is-ok" onClick={() => onStatus('ACTIVE', '')}>
                        Réactiver le compte
                      </button>
                    ) : (
                      <button type="button" className="nx-dossier-btn is-danger" onClick={() => setPending('SUSPENDED')}>
                        Suspendre le client
                      </button>
                    )}
                    {client.status !== 'CLOSED' ? (
                      <button type="button" className="nx-dossier-btn is-danger" onClick={() => setPending('CLOSED')}>
                        Clôturer le compte
                      </button>
                    ) : null}
                  </>
                )}
                {actionError ? <div className="nx-dossier-alert" role="alert">{actionError}</div> : null}
              </div>
            </div>
          ) : null}
        </motion.article>
      </motion.div>
    </AnimatePresence>
  );

  return createPortal(overlay, document.body);
}

function Field({ k, v }: { k: string; v: ReactNode }) {
  return (
    <div className="nx-dossier-field">
      <dt>{k}</dt>
      <dd>{v || '—'}</dd>
    </div>
  );
}

function TabBody({
  tab,
  client,
  isBusiness,
  pdfBusy,
  onPrintStatement,
  onExportStatement,
}: {
  tab: TabId;
  client: ControlClientDetail;
  isBusiness: boolean;
  fileId: string;
  pdfBusy: boolean;
  onPrintStatement: () => void;
  onExportStatement: () => void;
}) {
  if (tab === 'soldes') {
    return (
      <div className="nx-dossier-ledgers">
        {(['EUR', 'USD', 'XAF'] as const).map((cur) => (
          <div key={cur} className="nx-dossier-ledger">
            <span>{cur}</span>
            <strong>{money(client.balances[cur], cur)}</strong>
          </div>
        ))}
      </div>
    );
  }

  if (tab === 'moyens') {
    if (client.accounts.length === 0) {
      return <div className="nx-dossier-empty">Aucun moyen de paiement enregistré.</div>;
    }
    return (
      <>
        {client.accounts.map((a: ControlAccount) => (
          <div key={a.id} className="nx-dossier-card">
            <header>
              <span>{a.label}</span>
              <span>{a.kind}{a.is_default ? ' · défaut' : ''}</span>
            </header>
            <p>
              {a.holder_name ? <>Titulaire {a.holder_name}<br /></> : null}
              {a.operator ? <>Opérateur {a.operator}<br /></> : null}
              {a.network ? <>Réseau {a.network}<br /></> : null}
              {a.country ? <>Pays {countryLabel(a.country)}<br /></> : null}
              {a.city ? <>Ville {a.city}<br /></> : null}
              {a.address ? <>Adresse {a.address}<br /></> : null}
              {a.phone ? <>Téléphone {a.phone}</> : null}
            </p>
          </div>
        ))}
      </>
    );
  }

  if (tab === 'journal') {
    if (client.transactions.length === 0) {
      return <div className="nx-dossier-empty">Aucune opération sur ce compte.</div>;
    }
    return (
      <>
        <div className="nx-dossier-journal-actions">
          <span className="nx-dossier-journal-count">{client.transactions.length} opération{client.transactions.length > 1 ? 's' : ''}</span>
          <button type="button" className="nx-dossier-btn" disabled={pdfBusy} onClick={onPrintStatement}>
            Imprimer le relevé
          </button>
          <button type="button" className="nx-dossier-btn" disabled={pdfBusy} onClick={onExportStatement}>
            Télécharger PDF
          </button>
        </div>
        {client.transactions.map((tx, i) => (
          <div key={i} className="nx-dossier-tx">
            <b>{String(tx.label ?? tx.type ?? 'Opération')}</b>
            <span>{fmtTxAmount(tx)}</span>
            <span>{String(tx.status ?? '')}</span>
          </div>
        ))}
      </>
    );
  }

  return (
    <div className="nx-dossier-grid">
      <Field k="Type de compte" v={isBusiness ? 'Entreprise' : 'Personnel'} />
      <Field k="Pays de résidence" v={countryLabel(client.country_of_residence)} />
      <Field k="Téléphone" v={client.phone} />
      <Field k="Adresse" v={client.address} />
      <Field k="Ville" v={client.city} />
      <Field k="Code postal" v={client.postal_code} />
      <Field k="Membre depuis" v={new Date(client.created_at).toLocaleDateString('fr-FR')} />
      {!isBusiness && <Field k="Date de naissance" v={client.birth_date} />}
      {!isBusiness && <Field k="Genre" v={client.gender} />}
      {isBusiness && <Field k="Raison sociale" v={client.company_name || client.full_name} />}
      {isBusiness && <Field k="Forme juridique" v={client.legal_form} />}
      {isBusiness && <Field k="Immatriculation" v={client.company_registration_number} />}
      {isBusiness && <Field k="Secteur" v={client.industry} />}
      {isBusiness && <Field k="Taille" v={client.company_size} />}
      {isBusiness && (
        <Field
          k="Site web"
          v={client.website ? <a href={client.website} target="_blank" rel="noreferrer">{client.website}</a> : '—'}
        />
      )}
    </div>
  );
}
