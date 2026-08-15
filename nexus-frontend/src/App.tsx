import { BrowserRouter, Routes, Route, Navigate, useNavigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './context/AuthContext';
import { I18nProvider } from './context/I18nContext';
import { NotificationsProvider } from './context/NotificationsContext';
import { LandingPage } from './views/public/LandingPage';
import { LoginPage } from './views/auth/LoginPage';
import { RegisterPage } from './views/auth/RegisterPage';
import ForgotPasswordPage from './views/auth/ForgotPasswordPage';
import GearsBackground from './components/dashboard/GearsBackground';
import { ParticlesBackground } from './components/ParticlesBackground';
import Sidebar from './components/dashboard/Sidebar';
import DashTopbar from './components/dashboard/DashTopbar';
import DashboardPage from './views/dashboard/DashboardPage';
import WalletPage from './views/dashboard/WalletPage';
/* RoutingPage supprimé — le Routing Engine est intégré au workflow /send */
import NotificationsPage from './views/dashboard/NotificationsPage';
import SendPage from './views/dashboard/SendPage';
import ReceivePage from './views/dashboard/ReceivePage';
import ConvertPage from './views/dashboard/ConvertPage';
import HistoryPage from './views/dashboard/HistoryPage';
import SettingsPage from './views/dashboard/SettingsPage';
import KycPage from './views/dashboard/KycPage';
import AgentsPage from './views/dashboard/AgentsPage';
import BusinessDashboard from './views/business/BusinessDashboard';
import TreasuryPage from './views/business/TreasuryPage';
import PaymentsPage from './views/business/PaymentsPage';
import BeneficiariesPage from './views/business/BeneficiariesPage';
import ApprovalsPage from './views/business/ApprovalsPage';
import TeamPage from './views/business/TeamPage';
import ReconciliationPage from './views/business/ReconciliationPage';
import AnalyticsPage from './views/business/AnalyticsPage';
import ControlCenterLayout from './views/control/ControlCenterLayout';
import InternalLayout from './views/internal/InternalLayout';
import { InternalDashboardView, roleToDashboard } from './views/internal/InternalDashboards';
import NexusConnectPage from './views/connect/NexusConnectPage';
import { useDashT } from './data/dashboard-i18n';
import './styles/design-system.css';
import './styles/dashboard-system.css';
import './styles/premium.css';
import './styles/revolut.css';

type Mode = 'personal' | 'business';

/** Guard : Redirige vers /dashboard si l'utilisateur n'est pas un compte Business */
function BusinessRoute({ children }: { children: React.ReactNode }) {
  const { user } = useAuth();
  if (user?.account_type !== 'business') {
    return <Navigate to="/dashboard" replace />;
  }
  return <>{children}</>;
}

function PublicRouter() {
  const navigate = useNavigate();

  return (
    <>
      <div className="ambient-background" />
      <Routes>
        <Route
          path="/"
          element={
            <LandingPage
              onLogin={() => navigate('/login')}
              onRegister={() => navigate('/register')}
            />
          }
        />
        <Route
          path="/login"
          element={
            <LoginPage
              onSwitchToRegister={() => navigate('/register')}
              onBackHome={() => navigate('/')}
            />
          }
        />
        <Route
          path="/register"
          element={
            <RegisterPage
              onSwitchToLogin={() => navigate('/login')}
              onBackHome={() => navigate('/')}
            />
          }
        />
        <Route path="/forgot-password" element={<ForgotPasswordPage />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </>
  );
}

function DashboardLayout() {
  const { user } = useAuth();
  const t = useDashT();

  // Le mode est FIXÉ par le type de compte : un compte personal est toujours
  // personal, un compte business toujours business. Pas de bascule P/B.
  const isPersonalAccount = user?.account_type === 'personal';
  const effectiveMode: Mode = isPersonalAccount ? 'personal' : 'business';

  return (
    <div className="nexus-dash layout">
      {/* Fond bleu animé + particules (ambiance premium, comme la landing/login) */}
      <div className="dash-ambient-bg" aria-hidden="true" />
      <GearsBackground />
      <div className="bg-grid" />
      <div className="ambient">
        <div className="orb" style={{ width: 520, height: 520, top: '-12%', left: '2%', background: 'radial-gradient(circle, rgba(0,140,255,0.14), transparent 70%)' }} />
        <div className="orb" style={{ width: 420, height: 420, bottom: '2%', right: '6%', background: 'radial-gradient(circle, rgba(0,200,255,0.10), transparent 70%)' }} />
        <div className="orb" style={{ width: 300, height: 300, top: '42%', left: '48%', background: 'radial-gradient(circle, rgba(120,120,255,0.06), transparent 70%)' }} />
      </div>
      <ParticlesBackground density={55} color="#00C8FF" opacity={0.5} className="dash-particles" />
      <div className="ambient-vignette" aria-hidden="true" />

      <NotificationsProvider>
        <Sidebar mode={effectiveMode} />

        <div className="main-content">
          <Routes>
            <Route path="/dashboard" element={<><DashTopbar mode={effectiveMode} title={t('page.dashboard')} subtitle="" />{effectiveMode === 'business' ? <BusinessDashboard /> : <DashboardPage mode={effectiveMode} />}</>} />
            <Route path="/wallet" element={<><DashTopbar mode={effectiveMode} title={t('page.wallet')} subtitle="" /><WalletPage /></>} />
            {/* Redirection /routing → /send (routing intégré dans /send) */}
            <Route path="/routing" element={<Navigate to="/send" replace />} />
            <Route path="/send" element={<><DashTopbar mode={effectiveMode} title={t('page.send')} subtitle="" /><SendPage /></>} />
            <Route path="/receive" element={<><DashTopbar mode={effectiveMode} title={t('page.receive')} subtitle="" /><ReceivePage /></>} />
            <Route path="/convert" element={<><DashTopbar mode={effectiveMode} title={t('page.convert')} subtitle="" /><ConvertPage /></>} />
            <Route path="/history" element={<><DashTopbar mode={effectiveMode} title={t('page.history')} subtitle="" /><HistoryPage /></>} />
            <Route path="/notifications" element={<><DashTopbar mode={effectiveMode} title={t('page.notifications')} subtitle="" /><NotificationsPage /></>} />

            {/* Nexus Connect — dashboard client API / B2B */}
            <Route path="/connect" element={<><DashTopbar mode={effectiveMode} title="Nexus Connect" subtitle="" /><NexusConnectPage /></>} />

            {/* Routes réservées exclusivement aux comptes Business */}
            <Route path="/treasury" element={<BusinessRoute><DashTopbar mode={effectiveMode} title={t('page.treasury')} subtitle="" /><TreasuryPage /></BusinessRoute>} />
            <Route path="/payments" element={<BusinessRoute><DashTopbar mode={effectiveMode} title={t('page.payments')} subtitle="" /><PaymentsPage /></BusinessRoute>} />
            <Route path="/approvals" element={<BusinessRoute><DashTopbar mode={effectiveMode} title={t('page.approvals')} subtitle="" /><ApprovalsPage /></BusinessRoute>} />
            <Route path="/beneficiaries" element={<BusinessRoute><DashTopbar mode={effectiveMode} title={t('page.beneficiaries')} subtitle="" /><BeneficiariesPage /></BusinessRoute>} />
            <Route path="/reconciliation" element={<BusinessRoute><DashTopbar mode={effectiveMode} title={t('page.reconciliation')} subtitle="" /><ReconciliationPage /></BusinessRoute>} />
            <Route path="/team" element={<BusinessRoute><DashTopbar mode={effectiveMode} title={t('page.team')} subtitle="" /><TeamPage /></BusinessRoute>} />
            <Route path="/reporting" element={<BusinessRoute><DashTopbar mode={effectiveMode} title={t('page.reporting')} subtitle="" /><AnalyticsPage /></BusinessRoute>} />

            <Route path="/kyc" element={<><DashTopbar mode={effectiveMode} title={t('page.kyc')} subtitle="" /><KycPage /></>} />
            {/* /providers est une fonctionnalité du Back Office : plus accessible depuis les dashboards */}
            <Route path="/providers" element={<Navigate to="/dashboard" replace />} />
            <Route path="/agents" element={<><DashTopbar mode={effectiveMode} title={t('page.agents')} subtitle="" /><AgentsPage /></>} />
            <Route path="/settings" element={<><DashTopbar mode={effectiveMode} title={t('page.settings')} subtitle="" /><SettingsPage /></>} />
            <Route path="*" element={<Navigate to="/dashboard" replace />} />
          </Routes>
        </div>
      </NotificationsProvider>
    </div>
  );
}

function AppRoutes() {
  const { user, isLoaded } = useAuth();

  // Pendant la restauration de session, afficher un écran de chargement
  if (!isLoaded) {
    return (
      <div style={{
        position: 'fixed',
        inset: 0,
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        background: '#0B0B0F',
        color: '#E0E0E0',
        fontFamily: "'Inter', sans-serif",
        gap: '1rem',
      }}>
        <div className="nexus-spinner" />
        <span style={{ fontSize: '0.875rem', opacity: 0.6 }}>Chargement de la session…</span>
      </div>
    );
  }

  if (!user) return <PublicRouter />;

  // Rôle interne → dashboard interne RBAC.
  const internalDashboard = roleToDashboard(user.platform_role);

  return (
    <Routes>
      <Route path="/control/*" element={<ControlCenterLayout />} />
      {internalDashboard ? (
        <>
          <Route path="/internal" element={<InternalLayout><InternalDashboardView dashboard={internalDashboard} /></InternalLayout>} />
          <Route path="/internal/*" element={<InternalLayout><InternalDashboardView dashboard={internalDashboard} /></InternalLayout>} />
          {/* Les employés internes atterrissent sur leur dashboard interne */}
          <Route path="/dashboard" element={<Navigate to="/internal" replace />} />
          <Route path="/" element={<Navigate to="/internal" replace />} />
        </>
      ) : null}
      <Route path="*" element={<DashboardLayout />} />
    </Routes>
  );
}

function App() {
  return (
    <BrowserRouter>
      <I18nProvider>
        <AuthProvider>
          <AppRoutes />
        </AuthProvider>
      </I18nProvider>
    </BrowserRouter>
  );
}

export default App;
