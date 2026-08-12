import { useParams } from 'react-router-dom';
import { useI18n } from '../../context/I18nContext';

export default function PlaceholderPage() {
  const { '*': path } = useParams();
  const { t } = useI18n();

  // Dynamically map path to the matching sidebar translation key
  const getTitleKey = (p: string): string => {
    switch (p) {
      case 'send': return 'side_send';
      case 'history': return 'side_history';
      case 'nexus-pro': return 'side_pro';
      case 'treasury': return 'side_treasury';
      case 'payments': return 'side_payments';
      case 'approvals': return 'side_approvals';
      case 'team': return 'side_team';
      case 'reporting': return 'side_reporting';
      case 'kyc': return 'side_kyc';
      case 'agents': return 'side_agents';
      default: return 'side_dashboard';
    }
  };

  const titleKey = getTitleKey(path || '');
  const title = t(titleKey);
  
  return (
    <div className="page">
      <div className="page-header animate-up">
        <div className="page-label">NEXUS</div>
        <div className="page-title">{title}</div>
        <p className="animate-up delay-1" style={{ marginTop: 10, fontSize: 13, color: 'var(--text-mid)', maxWidth: 480 }}>
          {t('placeholder_title')}
        </p>
      </div>
    </div>
  );
}
