import { useParams } from 'react-router-dom';

const titles: Record<string, string> = {
  '/send': 'Envoyer de l\'argent',
  '/history': 'Historique des transactions',
  '/treasury': 'Trésorerie',
  '/payments': 'Paiements',
  '/approvals': 'Approbations',
  '/team': 'Équipe & Rôles',
  '/reporting': 'Reporting',
  '/kyc': 'KYC / KYB',
  '/providers': 'Provider Network',
  '/agents': 'Agents IA',
};

export default function PlaceholderPage() {
  const { '*': path } = useParams();
  const title = titles[`/${path || ''}`] || 'Page';
  
  return (
    <div className="page">
      <div className="page-header animate-up">
        <div className="page-label">NEXUS</div>
        <div className="page-title">{title}</div>
        <p className="animate-up delay-1" style={{ marginTop: 10, fontSize: 13, color: 'var(--text-mid)', maxWidth: 480 }}>
          Cette page est en cours de développement.
        </p>
      </div>
    </div>
  );
}
