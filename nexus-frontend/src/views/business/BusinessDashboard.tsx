import { useState } from 'react';
import './BusinessDashboard.css';

interface PaymentRequest {
  id: string;
  beneficiary: string;
  amount: number;
  currency: string;
  status: 'pending' | 'approved' | 'processing' | 'completed';
  created_at: string;
}

interface TeamMember {
  id: string;
  name: string;
  role: string;
  email: string;
  status: 'active' | 'pending';
}

export function BusinessDashboard() {
  const [payments] = useState<PaymentRequest[]>([
    {
      id: 'pay_001',
      beneficiary: 'Fournisseur Alpha SARL',
      amount: 15000,
      currency: 'EUR',
      status: 'pending',
      created_at: '2026-08-09T10:30:00Z',
    },
    {
      id: 'pay_002',
      beneficiary: 'Prestataire Beta',
      amount: 8500,
      currency: 'EUR',
      status: 'approved',
      created_at: '2026-08-09T09:15:00Z',
    },
    {
      id: 'pay_003',
      beneficiary: 'Freelance Gamma',
      amount: 3200,
      currency: 'EUR',
      status: 'processing',
      created_at: '2026-08-08T16:45:00Z',
    },
  ]);

  const [team] = useState<TeamMember[]>([
    { id: '1', name: 'Jean Dupont', role: 'Owner', email: 'jean@company.com', status: 'active' },
    { id: '2', name: 'Marie Curie', role: 'Finance Manager', email: 'marie@company.com', status: 'active' },
    { id: '3', name: 'Paul Martin', role: 'Operator', email: 'paul@company.com', status: 'active' },
    { id: '4', name: 'Sophie Bernard', role: 'Accountant', email: 'sophie@company.com', status: 'pending' },
  ]);

  const getStatusBadge = (status: PaymentRequest['status']) => {
    const config = {
      pending: { label: 'En attente', className: 'status-pending' },
      approved: { label: 'Approuvé', className: 'status-approved' },
      processing: { label: 'En cours', className: 'status-processing' },
      completed: { label: 'Terminé', className: 'status-completed' },
    };
    const { label, className } = config[status];
    return <span className={`status-badge ${className}`}>{label}</span>;
  };

  const formatCurrency = (amount: number, currency: string) => {
    return new Intl.NumberFormat('fr-FR', {
      style: 'currency',
      currency,
    }).format(amount);
  };

  const formatDate = (dateString: string) => {
    return new Intl.DateTimeFormat('fr-FR', {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    }).format(new Date(dateString));
  };

  return (
    <div className="business-dashboard">
      {/* Header Stats */}
      <section className="stats-section">
        <div className="stats-grid">
          <div className="stat-card glass-card">
            <div className="stat-icon">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <rect x="2" y="5" width="20" height="14" rx="2"/>
                <path d="M2 10h20"/>
              </svg>
            </div>
            <div className="stat-content">
              <span className="stat-value">€128,450</span>
              <span className="stat-label">Solde disponible</span>
            </div>
          </div>

          <div className="stat-card glass-card">
            <div className="stat-icon">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
              </svg>
            </div>
            <div className="stat-content">
              <span className="stat-value">€45,200</span>
              <span className="stat-label">En cours ce mois</span>
            </div>
          </div>

          <div className="stat-card glass-card">
            <div className="stat-icon">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
              </svg>
            </div>
            <div className="stat-content">
              <span className="stat-value">4</span>
              <span className="stat-label">Membres actifs</span>
            </div>
          </div>

          <div className="stat-card glass-card">
            <div className="stat-icon">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
              </svg>
            </div>
            <div className="stat-content">
              <span className="stat-value">99.2%</span>
              <span className="stat-label">Taux de succès</span>
            </div>
          </div>
        </div>
      </section>

      {/* Pending Approvals */}
      <section className="approvals-section">
        <div className="section-header">
          <h2 className="section-title">Approbations en attente</h2>
          <button className="btn btn-secondary">Voir tout</button>
        </div>

        <div className="approvals-list">
          {payments.filter(p => p.status === 'pending').map((payment) => (
            <div key={payment.id} className="approval-card glass-card">
              <div className="approval-header">
                <div className="approval-icon">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M12 6v6l4 2"/>
                  </svg>
                </div>
                <div className="approval-info">
                  <span className="approval-beneficiary">{payment.beneficiary}</span>
                  <span className="approval-date">{formatDate(payment.created_at)}</span>
                </div>
                {getStatusBadge(payment.status)}
              </div>

              <div className="approval-amount">
                {formatCurrency(payment.amount, payment.currency)}
              </div>

              <div className="approval-actions">
                <button className="btn btn-ghost btn-sm">Rejeter</button>
                <button className="btn btn-primary btn-sm">Approuver</button>
              </div>
            </div>
          ))}
        </div>
      </section>

      {/* Team & Recent Activity */}
      <section className="team-section">
        <div className="team-grid">
          <div className="team-card glass-card">
            <h3 className="card-title">Équipe</h3>
            <div className="team-list">
              {team.map((member) => (
                <div key={member.id} className="team-item">
                  <div className="team-avatar">
                    {member.name.split(' ').map(n => n[0]).join('')}
                  </div>
                  <div className="team-info">
                    <span className="team-name">{member.name}</span>
                    <span className="team-role">{member.role}</span>
                  </div>
                  <div className={`team-status ${member.status === 'active' ? 'status-active' : 'status-pending'}`}>
                    <span className="status-dot"></span>
                    {member.status === 'active' ? 'Actif' : 'En attente'}
                  </div>
                </div>
              ))}
            </div>
            <button className="btn btn-secondary btn-block" style={{ marginTop: '16px' }}>
              Inviter un membre
            </button>
          </div>

          <div className="activity-card glass-card">
            <h3 className="card-title">Activité récente</h3>
            <div className="activity-list">
              <div className="activity-item">
                <div className="activity-dot"></div>
                <div className="activity-content">
                  <p className="activity-text">Paiement approuvé - Fournisseur Alpha SARL</p>
                  <span className="activity-time">Il y a 2 heures</span>
                </div>
              </div>
              <div className="activity-item">
                <div className="activity-dot"></div>
                <div className="activity-content">
                  <p className="activity-text">Nouveau membre ajouté - Sophie Bernard</p>
                  <span className="activity-time">Il y a 5 heures</span>
                </div>
              </div>
              <div className="activity-item">
                <div className="activity-dot"></div>
                <div className="activity-content">
                  <p className="activity-text">Transfert complété - €8,500 vers RDC</p>
                  <span className="activity-time">Hier</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>
  );
}
