import { motion } from 'framer-motion';
import { useAuth } from '../../context/AuthContext';
import { pillForStatus, labelForStatus } from '../business/ui';
import { useDashT } from '../../data/dashboard-i18n';

const KYC_LEVELS = ['none', 'basic', 'standard', 'advanced'];

/** KYC / KYB — affiche l'état de vérification RÉEL du compte. */
export default function KycPage() {
  const t = useDashT();
  const { user } = useAuth();

  const status = user?.status ?? 'PENDING';
  const kyc = user?.kyc_level ?? 'none';
  const levelIndex = KYC_LEVELS.indexOf(kyc);
  const limits: Record<string, string> = {
    none: 'Aucun transfert autorisé',
    basic: '500 EUR / mois',
    standard: '2 000 EUR / mois',
    advanced: '10 000 EUR / mois',
  };

  return (
    <div className="page">
      <motion.div className="page-header animate-up" initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>
        <div className="page-label">{t('page.kyc').toUpperCase()}</div>
        <div className="page-title">{t('page.kyc')}</div>
        <p style={{ marginTop: 10, fontSize: 13, color: 'var(--text-mid)' }}>État réel de votre vérification d'identité, appliqué par le Policy Engine à chaque opération.</p>
      </motion.div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 16 }}>
        <div className="card" style={{ padding: 20 }}>
          <div className="page-label">Statut du compte</div>
          <div style={{ marginTop: 12 }}><span className={`pill ${pillForStatus(status === 'ACTIVE' ? 'active' : 'pending')}`}>{status}</span></div>
          <p style={{ marginTop: 10, fontSize: 12, color: 'var(--text-mid)' }}>
            {status === 'ACTIVE' ? 'Compte actif — opérations autorisées.' : 'Compte en attente de vérification — les transferts sont bloqués.'}
          </p>
        </div>

        <div className="card" style={{ padding: 20 }}>
          <div className="page-label">Niveau KYC</div>
          <div style={{ marginTop: 12 }}>
            <div style={{ display: 'flex', gap: 6 }}>
              {KYC_LEVELS.map(l => (
                <div key={l} style={{
                  flex: 1, height: 8, borderRadius: 4,
                  background: KYC_LEVELS.indexOf(l) <= levelIndex ? 'var(--cyan)' : 'var(--panel2)',
                }} />
              ))}
            </div>
          </div>
          <div style={{ marginTop: 10, fontWeight: 700, color: 'var(--text-bright)' }}>{labelForStatus(kyc)} · niveau « {kyc} »</div>
          <div style={{ marginTop: 6, fontSize: 12, color: 'var(--text-mid)' }}>Plafond : {limits[kyc] ?? '—'}</div>
        </div>

        <div className="card" style={{ padding: 20 }}>
          <div className="page-label">Vérification d'identité</div>
          <p style={{ marginTop: 12, fontSize: 12, color: 'var(--text-mid)', lineHeight: 1.6 }}>
            La vérification documentaire (Sumsub) n'est pas encore branchée à l'API.
            Pour le moment, le niveau KYC est géré côté backend par un opérateur agréé.
          </p>
          <div className="pill p" style={{ marginTop: 12 }}>Fonctionnalité désactivée en attente du provider KYC</div>
        </div>
      </div>
    </div>
  );
}
