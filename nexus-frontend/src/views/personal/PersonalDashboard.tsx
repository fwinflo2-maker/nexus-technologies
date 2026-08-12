import { useState } from 'react';
import { TorusField } from '../../components/TorusField';
import './PersonalDashboard.css';

interface TransferIntent {
  sourceCountry: string;
  destinationCountry: string;
  amount: number;
  currency: string;
  destinationType: string;
}

interface Route {
  route_id: string;
  provider: string;
  fees: number;
  estimated_delivery: string;
  reliability_score: number;
  received_amount: number;
}

export function PersonalDashboard() {
  const [intent, setIntent] = useState<TransferIntent>({
    sourceCountry: 'FR',
    destinationCountry: 'CG',
    amount: 500,
    currency: 'EUR',
    destinationType: 'mobile_money',
  });

  const [routes, setRoutes] = useState<Route[]>([]);
  const [loading, setLoading] = useState(false);
  const [selectedRoute, setSelectedRoute] = useState<string | null>(null);

  const findRoutes = async () => {
    setLoading(true);
    try {
      const response = await fetch('http://localhost:3001/api/intent', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(intent),
      });

      const result: any = await response.json();

      if (result.success && result.data?.routes) {
        setRoutes(result.data.routes);
      } else {
        console.error('Agent error:', result.error);
      }
    } catch (error) {
      console.error('Failed to connect to agents:', error);
    } finally {
      setLoading(false);
    }
  };

  const selectedRouteData = routes.find(r => r.route_id === selectedRoute);

  return (
    <div className="personal-dashboard">
      {/* Hero Section with 3D Torus */}
      <section className="hero-section">
        <div className="hero-content">
          <h1 className="hero-title">
            Transférez l'argent <span className="text-gradient">intelligemment</span>
          </h1>
          <p className="hero-subtitle">
            NEXUS analyse, compare et orchestre les meilleures routes pour vos transferts internationaux.
          </p>
        </div>
        <div className="hero-visual">
          <TorusField size={400} />
        </div>
      </section>

      {/* Transfer Simulator */}
      <section className="transfer-section">
        <div className="transfer-card glass-card">
          <h2 className="section-title">Simulateur de transfert</h2>

          <div className="transfer-form">
            <div className="form-row">
              <div className="form-group">
                <label className="form-label">Montant</label>
                <div className="input-group">
                  <input
                    type="number"
                    className="form-input"
                    value={intent.amount}
                    onChange={(e) => setIntent({ ...intent, amount: Number(e.target.value) })}
                  />
                  <select
                    className="form-select"
                    value={intent.currency}
                    onChange={(e) => setIntent({ ...intent, currency: e.target.value })}
                  >
                    <option value="EUR">EUR</option>
                    <option value="USD">USD</option>
                    <option value="XAF">XAF</option>
                    <option value="GBP">GBP</option>
                  </select>
                </div>
              </div>

              <div className="form-group">
                <label className="form-label">De</label>
                <select
                  className="form-select form-select-full"
                  value={intent.sourceCountry}
                  onChange={(e) => setIntent({ ...intent, sourceCountry: e.target.value })}
                >
                  <option value="FR">France</option>
                  <option value="BE">Belgique</option>
                  <option value="DE">Allemagne</option>
                  <option value="GB">Royaume-Uni</option>
                </select>
              </div>

              <div className="form-group">
                <label className="form-label">Vers</label>
                <select
                  className="form-select form-select-full"
                  value={intent.destinationCountry}
                  onChange={(e) => setIntent({ ...intent, destinationCountry: e.target.value })}
                >
                  <option value="CG">Congo</option>
                  <option value="CD">RDC</option>
                  <option value="SN">Sénégal</option>
                  <option value="CI">Côte d'Ivoire</option>
                </select>
              </div>

              <div className="form-group">
                <label className="form-label">Destination</label>
                <select
                  className="form-select form-select-full"
                  value={intent.destinationType}
                  onChange={(e) => setIntent({ ...intent, destinationType: e.target.value })}
                >
                  <option value="mobile_money">Mobile Money</option>
                  <option value="bank">Compte bancaire</option>
                  <option value="wallet">Portefeuille</option>
                </select>
              </div>
            </div>

            <button
              className="btn btn-primary btn-lg"
              onClick={findRoutes}
              disabled={loading}
            >
              {loading ? (
                <>
                  <span className="spinner"></span>
                  Analyse via NEXUS Agents...
                </>
              ) : (
                <>
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <circle cx="11" cy="11" r="8"/>
                    <path d="m21 21-4.35-4.35"/>
                  </svg>
                  Trouver les routes avec NEXUS Intelligence
                </>
              )}
            </button>
          </div>
        </div>
      </section>

      {/* Routes Results */}
      {routes.length > 0 && (
        <section className="routes-section">
          <h2 className="section-title">Routes disponibles</h2>
          <div className="routes-grid">
            {routes.map((route) => (
              <div
                key={route.route_id}
                className={`route-card glass-card ${selectedRoute === route.route_id ? 'selected' : ''}`}
                onClick={() => setSelectedRoute(route.route_id)}
              >
                <div className="route-header">
                  <div className="route-provider">{route.provider}</div>
                  <div className="route-reliability">
                    <span className="reliability-score">{(route.reliability_score * 100).toFixed(0)}%</span>
                    <span className="reliability-label">fiabilité</span>
                  </div>
                </div>

                <div className="route-body">
                  <div className="route-amount">
                    <span className="amount-label">Vous recevez</span>
                    <span className="amount-value">
                      {route.received_amount.toLocaleString()} XAF
                    </span>
                  </div>

                  <div className="route-details">
                    <div className="route-detail">
                      <span className="detail-label">Frais</span>
                      <span className="detail-value">{route.fees.toFixed(2)} EUR</span>
                    </div>
                    <div className="route-detail">
                      <span className="detail-label">Délai</span>
                      <span className="detail-value">{route.estimated_delivery}</span>
                    </div>
                  </div>
                </div>

                <div className="route-footer">
                  <button className={`btn ${selectedRoute === route.route_id ? 'btn-primary' : 'btn-secondary'} btn-block`}>
                    {selectedRoute === route.route_id ? 'Sélectionné' : 'Sélectionner'}
                  </button>
                </div>
              </div>
            ))}
          </div>

          {/* Route Detail Panel */}
          {selectedRouteData && (
            <div className="route-detail-panel glass-card animate-fade-in">
              <h3 className="panel-title">Détails de la route</h3>
              <div className="panel-grid">
                <div className="panel-item">
                  <span className="panel-label">Provider</span>
                  <span className="panel-value">{selectedRouteData.provider}</span>
                </div>
                <div className="panel-item">
                  <span className="panel-label">Montant envoyé</span>
                  <span className="panel-value">{intent.amount} {intent.currency}</span>
                </div>
                <div className="panel-item">
                  <span className="panel-label">Montant reçu</span>
                  <span className="panel-value">{selectedRouteData.received_amount.toLocaleString()} XAF</span>
                </div>
                <div className="panel-item">
                  <span className="panel-label">Frais Nexus</span>
                  <span className="panel-value">{selectedRouteData.fees.toFixed(2)} EUR</span>
                </div>
                <div className="panel-item">
                  <span className="panel-label">Délai estimé</span>
                  <span className="panel-value">{selectedRouteData.estimated_delivery}</span>
                </div>
                <div className="panel-item">
                  <span className="panel-label">Fiabilité</span>
                  <span className="panel-value">{(selectedRouteData.reliability_score * 100).toFixed(0)}%</span>
                </div>
              </div>
              <button className="btn btn-primary btn-lg btn-block">
                Confirmer le transfert
              </button>
            </div>
          )}
        </section>
      )}
    </div>
  );
}
