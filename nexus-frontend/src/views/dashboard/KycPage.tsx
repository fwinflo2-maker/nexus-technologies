import { useEffect, useState } from 'react';
import { motion } from 'framer-motion';
import { useAuth } from '../../context/AuthContext';
import { pillForStatus, labelForStatus } from '../business/ui';
import { useDashT } from '../../data/dashboard-i18n';
import { apiKycStatus, apiKycSession } from '../../api/client';
import type { KycStatusData } from '../../api/client';

const KYC_LEVELS = ['none', 'basic', 'standard', 'advanced'];

const LIVE_KYC: Record<string, { label: string; tone: 'pending' | 'active' | 'p' }> = {
  not_started: { label: 'Non démarrée', tone: 'pending' },
  in_progress: { label: 'En cours', tone: 'pending' },
  pending: { label: 'En examen', tone: 'pending' },
  verified: { label: 'Approuvée', tone: 'active' },
  resubmission_requested: { label: 'Documents à resoumettre', tone: 'p' },
  rejected: { label: 'Refusée', tone: 'p' },
  on_hold: { label: 'En revue', tone: 'pending' },
};

const KYB_STATES: Record<string, { label: string; tone: 'pending' | 'active' | 'p' }> = {
  none: { label: 'Non démarrée', tone: 'pending' },
  in_progress: { label: 'En cours', tone: 'pending' },
  pending: { label: 'En examen', tone: 'pending' },
  verified: { label: 'Entreprise vérifiée', tone: 'active' },
  resubmission_requested: { label: 'Documents à resoumettre', tone: 'p' },
  rejected: { label: 'Refusée', tone: 'pending' },
  on_hold: { label: 'En attente de revue', tone: 'pending' },
};

const RISK_LEVELS: Record<string, { label: string; tone: 'pending' | 'active' | 'p' }> = {
  low: { label: 'Risque faible', tone: 'active' },
  medium: { label: 'Risque modéré', tone: 'pending' },
  high: { label: 'Risque élevé', tone: 'p' },
};

/** Charge dynamiquement le WebSDK Sumsub (documentation officielle). */
function loadSumsubWebSdk(): Promise<boolean> {
  return new Promise(resolve => {
    if (typeof window !== 'undefined' && (window as any).SumsubWebSdk) {
      resolve(true);
      return;
    }
    const s = document.createElement('script');
    s.src = 'https://static.sumsub.com/idensic/static/sns-websdk-bundle.js';
    s.async = true;
    s.onload = () => resolve(Boolean((window as any).SumsubWebSdk));
    s.onerror = () => resolve(false);
    document.head.appendChild(s);
  });
}

/**
 * KYC / KYB — affiche l'état RÉEL de la vérification d'identité.
 *
 * Le frontend ne peut JAMAIS déclarer un utilisateur vérifié : seul un webhook
 * Sumsub signé (lu via l'API) fait autorité. Le statut affiché vient donc de
 * GET /api/kyc/status. Pour un compte Business, le sujet est l'entreprise
 * (KYB, subject_type=company) — un état distinct du KYC individuel.
 */
export default function KycPage() {
  const t = useDashT();
  const { user } = useAuth();

  const isBusiness = user?.account_type === 'business';
  const status = user?.status ?? 'PENDING';
  const kyc = user?.kyc_level ?? 'none';
  const kyb = user?.kyb_status ?? (isBusiness ? 'none' : undefined);
  const levelIndex = KYC_LEVELS.indexOf(kyc);

  const [live, setLive] = useState<KycStatusData | null>(null);
  const [loading, setLoading] = useState(true);
  const [starting, setStarting] = useState(false);
  const [sessionMsg, setSessionMsg] = useState('');
  const [error, setError] = useState('');

  const configured = live?.configured ?? false;

  useEffect(() => {
    let alive = true;
    apiKycStatus()
      .then(r => {
        if (!alive) return;
        if (r.success && r.data) setLive(r.data);
        else setError(r.error ?? 'Impossible de récupérer le statut.');
      })
      .finally(() => alive && setLoading(false));
    return () => {
      alive = false;
    };
  }, []);

  const startVerification = async () => {
    setStarting(true);
    setSessionMsg('');
    setError('');
    try {
      const r = await apiKycSession();
      if (!r.success) {
        setError(r.error ?? 'Impossible de démarrer la vérification.');
        return;
      }
      if (r.data?.token) {
        const ok = await loadSumsubWebSdk();
        if (!ok) {
          setError('Le module de vérification Sumsub n’a pas pu être chargé.');
          return;
        }
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const sdk = (window as any).SumsubWebSdk;
        sdk.init(r.data.token, { lang: 'fr', theme: 'dark' });
        setSessionMsg('Fenêtre de vérification Sumsub ouverte. Complétez l’examen puis patientez pendant la revue.');
      }
    } finally {
      setStarting(false);
    }
  };

  const kybState = KYB_STATES[kyb ?? 'none'] ?? KYB_STATES.none;
  const liveStatus = live?.status ?? 'not_started';
  const liveUi = LIVE_KYC[liveStatus] ?? LIVE_KYC.not_started;

  return (
    <div className="page">
      <motion.div className="page-header animate-up" initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>
        <div className="page-label">{t('page.kyc').toUpperCase()}</div>
        <div className="page-title">{t('page.kyc')}</div>
        <p style={{ marginTop: 10, fontSize: 13, color: 'var(--text-mid)' }}>
          {isBusiness
            ? 'Vérification d’entreprise (KYB) déléguée à Sumsub. Tant que votre entreprise n’est pas vérifiée, les paiements sont bloqués.'
            : 'État réel de votre vérification d’identité, appliqué par le Policy Engine à chaque opération.'}
        </p>
      </motion.div>

      {loading && (
        <div className="card" style={{ padding: 24 }}>
          <div className="page-label">Chargement du statut…</div>
        </div>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 16 }}>
        <div className="card" style={{ padding: 20 }}>
          <div className="page-label">Statut du compte</div>
          <div style={{ marginTop: 12 }}>
            <span className={`pill ${pillForStatus(status === 'ACTIVE' ? 'active' : 'pending')}`}>{status}</span>
          </div>
          <p style={{ marginTop: 10, fontSize: 12, color: 'var(--text-mid)' }}>
            {status === 'ACTIVE'
              ? 'Compte actif — les paiements restent soumis à la vérification ' + (isBusiness ? 'd’entreprise' : 'd’identité') + '.'
              : 'Compte en attente de vérification — les transferts sont bloqués.'}
          </p>
        </div>

        <div className="card" style={{ padding: 20 }}>
          <div className="page-label">Statut provider (serveur)</div>
          <div style={{ marginTop: 12 }}>
            <span className={`pill ${liveUi.tone}`}>{liveUi.label}</span>
          </div>
          <p style={{ marginTop: 10, fontSize: 12, color: 'var(--text-mid)', lineHeight: 1.6 }}>
            {loading
              ? 'Chargement du statut serveur…'
              : `Source : GET /api/kyc/status · ${liveStatus}${live?.required_action ? ` · action ${live.required_action}` : ''}. Le frontend n’affiche jamais « terminé » avant cet état.`}
          </p>
          {live?.reason && (
            <p style={{ marginTop: 8, fontSize: 12, color: 'var(--text-mid)' }}>{live.reason}</p>
          )}
        </div>

        {isBusiness ? (
          <div className="card" style={{ padding: 20 }}>
            <div className="page-label">Vérification d’entreprise (KYB)</div>
            <div style={{ marginTop: 12 }}>
              <span className={`pill ${kybState.tone}`}>{kybState.label}</span>
            </div>
            {live?.risk_level && (
              <div style={{ marginTop: 10 }}>
                <span className={`pill ${RISK_LEVELS[live.risk_level]?.tone ?? 'pending'}`}>
                  {RISK_LEVELS[live.risk_level]?.label ?? live.risk_level}
                </span>
                <p style={{ marginTop: 8, fontSize: 11, color: 'var(--text-mid)' }}>
                  Niveau de risque évalué selon votre pays de résidence et votre secteur d’activité.
                </p>
              </div>
            )}
            {live?.kyb_verified_at && (
              <p style={{ marginTop: 8, fontSize: 11, color: 'var(--text-mid)' }}>
                Vérifiée le {new Date(live.kyb_verified_at).toLocaleDateString('fr-FR')}
              </p>
            )}
            <p style={{ marginTop: 10, fontSize: 12, color: 'var(--text-mid)', lineHeight: 1.6 }}>
              Entreprise soumise à Sumsub (subject_type = company) : la société, ses représentants et ses bénéficiaires effectifs sont examinés.
            </p>
          </div>
        ) : (
          <div className="card" style={{ padding: 20 }}>
            <div className="page-label">Niveau KYC</div>
            <div style={{ marginTop: 12 }}>
              <div style={{ display: 'flex', gap: 6 }}>
                {KYC_LEVELS.map(l => (
                  <div
                    key={l}
                    style={{
                      flex: 1, height: 8, borderRadius: 4,
                      background: KYC_LEVELS.indexOf(l) <= levelIndex ? 'var(--cyan)' : 'var(--panel2)',
                    }}
                  />
                ))}
              </div>
            </div>
            <div style={{ marginTop: 10, fontWeight: 700, color: 'var(--text-bright)' }}>
              {labelForStatus(kyc)} · niveau « {kyc} »
            </div>
          </div>
        )}

        <div className="card" style={{ padding: 20, gridColumn: isBusiness ? '1 / -1' : undefined }}>
          <div className="page-label">Démarche de vérification</div>
          {!configured && !loading && (
            <>
              <p style={{ marginTop: 12, fontSize: 12, color: 'var(--text-mid)', lineHeight: 1.6 }}>
                Le provider de vérification (Sumsub) n’est pas configuré pour cet environnement. Aucune session ne peut
                être ouverte tant que les identifiants {isBusiness ? 'KYB' : 'KYC'} ne sont pas fournis. Le statut reste donc « non vérifié » — aucune
                simulation.
              </p>
              <div className="pill p" style={{ marginTop: 12 }}>Provider KYC non configuré</div>
            </>
          )}

          {configured && !error && (
            <div style={{ marginTop: 12 }}>
              <motion.button
                className="btn btn-primary"
                whileTap={{ scale: 0.98 }}
                onClick={startVerification}
                disabled={starting}
              >
                {starting ? 'Ouverture…' : isBusiness ? 'Vérifier mon entreprise' : 'Vérifier mon identité'}
              </motion.button>
              {sessionMsg && <p style={{ marginTop: 10, fontSize: 12, color: 'var(--cyan)' }}>{sessionMsg}</p>}
            </div>
          )}

          {error && (
            <div style={{ marginTop: 12 }}>
              <div className="pill pending">{error}</div>
              {error.includes('configuré') && !configured && (
                <p style={{ marginTop: 8, fontSize: 12, color: 'var(--text-mid)' }}>
                  Vérification indisponible pour le moment — revenez dès que la configuration du provider est active.
                </p>
              )}
            </div>
          )}

          {live?.provider && (
            <p style={{ marginTop: 12, fontSize: 11, color: 'var(--text-mid)' }}>
              Provider : {live.provider} · environnement {live.environment} · sujet {live.verification_type}
            </p>
          )}
        </div>
      </div>
    </div>
  );
}
