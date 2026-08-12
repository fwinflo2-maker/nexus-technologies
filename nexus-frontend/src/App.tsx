import { useState } from 'react';
import { BrowserRouter, Routes, Route, Navigate, useNavigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './context/AuthContext';
import { I18nProvider, useI18n } from './context/I18nContext';
import { NotificationsProvider } from './context/NotificationsContext';
import { LandingPage } from './views/public/LandingPage';
import { LoginPage } from './views/auth/LoginPage';
import { RegisterPage } from './views/auth/RegisterPage';
import ForgotPasswordPage from './views/auth/ForgotPasswordPage';
import GearsBackground from './components/dashboard/GearsBackground';
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
import PlaceholderPage from './views/dashboard/PlaceholderPage';
import './styles/design-system.css';
import './styles/dashboard-system.css';

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
  const [mode, setMode] = useState<Mode>('personal');
  const { user } = useAuth();
  const { t } = useI18n();

  // Un compte personal est TOUJOURS en mode personal. Il n'a aucun accès aux fonctions Business.
  const isPersonalAccount = user?.account_type === 'personal';
  const effectiveMode: Mode = isPersonalAccount ? 'personal' : (user?.account_type === 'business' ? mode : 'personal');

  return (
    <div className="nexus-dash layout">
      <GearsBackground />
      <div className="bg-grid" />
      <div className="ambient">
        <div className="orb" style={{ width: 500, height: 500, top: '-10%', left: '5%', background: 'radial-gradient(circle, rgba(0,200,255,0.08), transparent 70%)' }} />
        <div className="orb" style={{ width: 400, height: 400, bottom: '5%', right: '10%', background: 'radial-gradient(circle, rgba(139,92,246,0.06), transparent 70%)' }} />
        <div className="orb" style={{ width: 300, height: 300, top: '40%', left: '50%', background: 'radial-gradient(circle, rgba(234,184,48,0.04), transparent 70%)' }} />
      </div>

      <NotificationsProvider>
        <Sidebar mode={effectiveMode} onModeChange={setMode} />

        <div className="main-content">
          <Routes>
            <Route path="/dashboard" element={<><DashTopbar mode={effectiveMode} title={t('side_dashboard')} subtitle="Vue d'ensemble" /><DashboardPage mode={effectiveMode} /></>} />
            <Route path="/wallet" element={<><DashTopbar mode={effectiveMode} title={t('side_wallet')} subtitle="Multi-devises" /><WalletPage /></>} />
            {/* Redirection /routing → /send (routing intégré dans /send) */}
            <Route path="/routing" element={<Navigate to="/send" replace />} />
            <Route path="/send" element={<><DashTopbar mode={effectiveMode} title={t('side_send')} subtitle="Transfert international" /><SendPage /></>} />
            <Route path="/receive" element={<><DashTopbar mode={effectiveMode} title={t('side_receive')} subtitle="Recevoir un transfert" /><ReceivePage /></>} />
            <Route path="/convert" element={<><DashTopbar mode={effectiveMode} title={t('side_convert')} subtitle="Conversion de devises" /><ConvertPage /></>} />
            <Route path="/history" element={<><DashTopbar mode={effectiveMode} title={t('side_history')} subtitle="Transactions" /><HistoryPage /></>} />
            <Route path="/nexus-pro" element={<><DashTopbar mode={effectiveMode} title={t('side_pro')} subtitle="Intelligence financière" /><PlaceholderPage /></>} />
            <Route path="/notifications" element={<><DashTopbar mode={effectiveMode} title={t('side_notifications')} subtitle="Centre de notification" /><NotificationsPage /></>} />

            {/* Routes réservées exclusivement aux comptes Business */}
            <Route path="/treasury" element={<BusinessRoute><DashTopbar mode={effectiveMode} title={t('side_treasury')} subtitle="Business" /><PlaceholderPage /></BusinessRoute>} />
            <Route path="/payments" element={<BusinessRoute><DashTopbar mode={effectiveMode} title={t('side_payments')} subtitle="Business" /><PlaceholderPage /></BusinessRoute>} />
            <Route path="/approvals" element={<BusinessRoute><DashTopbar mode={effectiveMode} title={t('side_approvals')} subtitle="Business" /><PlaceholderPage /></BusinessRoute>} />
            <Route path="/team" element={<BusinessRoute><DashTopbar mode={effectiveMode} title={t('side_team')} subtitle="Rôles & permissions" /><PlaceholderPage /></BusinessRoute>} />
            <Route path="/reporting" element={<BusinessRoute><DashTopbar mode={effectiveMode} title={t('side_reporting')} subtitle="Business" /><PlaceholderPage /></BusinessRoute>} />

            <Route path="/kyc" element={<><DashTopbar mode={effectiveMode} title="KYC / KYB" subtitle="Conformité" /><PlaceholderPage /></>} />
            {/* /providers est une fonctionnalité du Back Office : plus accessible depuis les dashboards */}
            <Route path="/providers" element={<Navigate to="/dashboard" replace />} />
            <Route path="/agents" element={<><DashTopbar mode={effectiveMode} title={t('side_agents')} subtitle="Système intelligent" /><PlaceholderPage /></>} />
            <Route path="/settings" element={<><DashTopbar mode={effectiveMode} title={t('side_settings')} subtitle="Gestion du compte" /><SettingsPage /></>} />
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

  return user ? <DashboardLayout /> : <PublicRouter />;
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
