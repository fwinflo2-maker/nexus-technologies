import { BrowserRouter, Routes, Route, Navigate, useNavigate, useLocation } from 'react-router-dom';
import { AuthProvider, useAuth, loginPathForLocation } from './context/AuthContext';
import { I18nProvider } from './context/I18nContext';
import { NotificationsProvider } from './context/NotificationsContext';
import SupportChatWidget from './components/chat/SupportChatWidget';
import { LandingPage } from './views/public/LandingPage';
import { LoginPage } from './views/auth/LoginPage';
import { RegisterPage } from './views/auth/RegisterPage';
import ForgotPasswordPage from './views/auth/ForgotPasswordPage';
import AdminLoginPage from './views/auth/AdminLoginPage';
import EmployeeLoginPage from './views/auth/EmployeeLoginPage';
import StaffHome from './views/staff/StaffHome';
import { PrivacyPage, TermsPage, DocumentationPage, SupportPage } from './views/public/InfoPages';
import GearsBackground from './components/dashboard/GearsBackground';
import { ParticlesBackground } from './components/ParticlesBackground';
import { SeoMeta } from './components/SeoMeta';
import Sidebar from './components/dashboard/Sidebar';
import DashTopbar from './components/dashboard/DashTopbar';
import DashboardPage from './views/dashboard/DashboardPage';
import WalletPage from './views/dashboard/WalletPage';
/* RoutingPage supprimé — le Routing Engine est intégré au workflow /send */
import NotificationsPage from './views/dashboard/NotificationsPage';
import SendPage from './views/dashboard/SendPage';
import ReceivePage from './views/dashboard/ReceivePage';
import ConvertPage from './views/dashboard/ConvertPage';
import CardsPage from './views/dashboard/CardsPage';
import HistoryPage from './views/dashboard/HistoryPage';
import SettingsPage from './views/dashboard/SettingsPage';
import KycPage from './views/dashboard/KycPage';
import AgentsPage from './views/dashboard/AgentsPage';
import BusinessDashboard from './views/business/BusinessDashboard';
import PaymentsPage from './views/business/PaymentsPage';
import BeneficiariesPage from './views/business/BeneficiariesPage';
import ApprovalsPage from './views/business/ApprovalsPage';
import TeamPage from './views/business/TeamPage';
import ReconciliationPage from './views/business/ReconciliationPage';
import TreasuryPage from './views/business/TreasuryPage';
import SuperAdminDashboard from './views/admin/SuperAdminDashboard';
import { useDashT } from './data/dashboard-i18n';
import './styles/design-system.css';
import './styles/dashboard-system.css';
import './styles/premium.css';
import './styles/premium-anim.css';
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
              onAdminLogin={() => navigate('/admin-login')}
              onRegister={(type) => navigate(type ? `/register?type=${type}` : '/register')}
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
        <Route path="/admin-login" element={<AdminLoginPage />} />
        <Route path="/staff-login" element={<EmployeeLoginPage />} />
        <Route path="/privacy" element={<PrivacyPage />} />
        <Route path="/terms" element={<TermsPage />} />
        <Route path="/docs" element={<DocumentationPage />} />
        <Route path="/support" element={<SupportPage />} />
        {/* Toute route inconnue ou protégée atteinte sans session (ex. juste
            après une déconnexion) mène au login de l'espace correspondant. */}
        <Route path="*" element={<PublicAuthFallback />} />
      </Routes>
    </>
  );
}

/** Redirige vers /login, /admin-login ou /staff-login selon l'URL d'origine. */
function PublicAuthFallback() {
  const { pathname } = useLocation();
  return <Navigate to={loginPathForLocation(pathname)} replace />;
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
            {/* /dashboard : le Super Admin est redirigé vers son centre de
                contrôle /admin. Il accède à Envoyer/Portefeuille via /send et
                /wallet (liens dédiés dans la sidebar admin). */}
            <Route path="/dashboard" element={user?.platform_role === 'superadmin'
              ? <Navigate to="/admin" replace />
              : <><DashTopbar mode={effectiveMode} title={t('page.dashboard')} subtitle="" />{effectiveMode === 'business' ? <BusinessDashboard /> : <DashboardPage mode={effectiveMode} />}</>} />
            <Route path="/wallet" element={<><DashTopbar mode={effectiveMode} title={t('page.wallet')} subtitle="" /><WalletPage /></>} />
            {/* Redirection /routing → /send (routing intégré dans /send) */}
            <Route path="/routing" element={<Navigate to="/send" replace />} />
            <Route path="/send" element={<><DashTopbar mode={effectiveMode} title={t('page.send')} subtitle="" /><SendPage /></>} />
            <Route path="/receive" element={<><DashTopbar mode={effectiveMode} title={t('page.receive')} subtitle="" /><ReceivePage /></>} />
            <Route path="/convert" element={<><DashTopbar mode={effectiveMode} title={t('page.convert')} subtitle="" /><ConvertPage /></>} />
            <Route path="/cards" element={<><DashTopbar mode={effectiveMode} title={t('page.cards')} subtitle="" /><CardsPage /></>} />
            <Route path="/history" element={<><DashTopbar mode={effectiveMode} title={t('page.history')} subtitle="" /><HistoryPage /></>} />
            <Route path="/notifications" element={<><DashTopbar mode={effectiveMode} title={t('page.notifications')} subtitle="" /><NotificationsPage /></>} />

            {/* Routes réservées exclusivement aux comptes Business. */}
            <Route path="/treasury" element={<BusinessRoute><DashTopbar mode={effectiveMode} title={t('page.treasury')} subtitle="" /><TreasuryPage /></BusinessRoute>} />
            <Route path="/payments" element={<BusinessRoute><DashTopbar mode={effectiveMode} title={t('page.payments')} subtitle="" /><PaymentsPage /></BusinessRoute>} />
            <Route path="/approvals" element={<BusinessRoute><DashTopbar mode={effectiveMode} title={t('page.approvals')} subtitle="" /><ApprovalsPage /></BusinessRoute>} />
            <Route path="/beneficiaries" element={<BusinessRoute><DashTopbar mode={effectiveMode} title={t('page.beneficiaries')} subtitle="" /><BeneficiariesPage /></BusinessRoute>} />
            <Route path="/reconciliation" element={<BusinessRoute><DashTopbar mode={effectiveMode} title={t('page.reconciliation')} subtitle="" /><ReconciliationPage /></BusinessRoute>} />
            <Route path="/team" element={<BusinessRoute><DashTopbar mode={effectiveMode} title={t('page.team')} subtitle="" /><TeamPage /></BusinessRoute>} />

            <Route path="/kyc" element={<><DashTopbar mode={effectiveMode} title={t('page.kyc')} subtitle="" /><KycPage /></>} />
            <Route path="/agents" element={<><DashTopbar mode={effectiveMode} title={t('page.agents')} subtitle="" /><AgentsPage /></>} />
            <Route path="/settings" element={<><DashTopbar mode={effectiveMode} title={t('page.settings')} subtitle="" /><SettingsPage /></>} />
            <Route path="*" element={<Navigate to="/dashboard" replace />} />
          </Routes>
        </div>

        {/* Widget chat support (tous les dashboards client) */}
        <SupportChatWidget />
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

  // Dashboard Super Admin : réservé au rôle superadmin. Les autres comptes
  // sont redirigés vers leur dashboard client.
  const isSuperAdmin = user.platform_role === 'superadmin';
  const isInternalStaff = user.platform_role !== 'user';

  return (
    <Routes>
      {isSuperAdmin && (
        <Route path="/admin" element={
          <div className="nexus-dash layout">
            <div className="dash-ambient-bg" aria-hidden="true" />
            <GearsBackground />
            <div className="bg-grid" />
            <ParticlesBackground density={45} color="#3B82F6" opacity={0.4} className="dash-particles" />
            <div className="ambient-vignette" aria-hidden="true" />
            <div className="main-content" style={{ marginLeft: 0 }}>
              <SuperAdminDashboard />
            </div>
          </div>
        } />
      )}
      {isInternalStaff && (
        <Route path="/staff" element={<StaffHome />} />
      )}
      <Route path="*" element={<DashboardLayout />} />
    </Routes>
  );
}

function App() {
  return (
    <BrowserRouter>
      <I18nProvider>
        <SeoMeta />
        <AuthProvider>
          <AppRoutes />
        </AuthProvider>
      </I18nProvider>
    </BrowserRouter>
  );
}

export default App;
