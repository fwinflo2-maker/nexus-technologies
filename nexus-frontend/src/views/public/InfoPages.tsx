import { type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { ParticlesBackground } from '../../components/ParticlesBackground';
import { LanguageSwitcher } from '../../components/LanguageSwitcher';
import './LandingPage.css';

/** En-tête commun aux pages d'information (Confidentialité, Conditions, …). */
function InfoLayout({ title, kicker, children }: { title: string; kicker: string; children: ReactNode }) {
  const navigate = useNavigate();
  return (
    <div className="landing-page">
      <ParticlesBackground />
      <nav className="site-nav">
        <div className="site-nav-inner">
          <div className="nav-brand">
            <svg width="28" height="28" viewBox="0 0 32 32" fill="none"><path d="M16 2L28 9V23L16 30L4 23V9L16 2Z" stroke="url(#lg-nav)" strokeWidth="2" fill="none"/><path d="M16 10L22 14V22L16 26L10 22V14L16 10Z" fill="url(#lg-nav)"/><defs><linearGradient id="lg-nav" x1="4" y1="2" x2="28" y2="30"><stop stopColor="#7C3AED"/><stop offset="1" stopColor="#a855f7"/></linearGradient></defs></svg>
            <span className="brand-text">NEXUS</span>
          </div>
          <div className="nav-links">
            <button className="btn btn-ghost btn-sm" onClick={() => navigate('/')}>← Retour</button>
            <LanguageSwitcher />
          </div>
        </div>
      </nav>

      <section className="section-block container" style={{ maxWidth: 820 }}>
        <div className="section-heading" style={{ textAlign: 'left', marginBottom: 28 }}>
          <span className="section-kicker">{kicker}</span>
          <h2 style={{ margin: '0.4rem 0 0.6rem' }}>{title}</h2>
          <p style={{ color: 'var(--text-faint)', fontSize: '0.85rem' }}>Dernière mise à jour : août 2026</p>
        </div>
        <div className="info-body" style={{ display: 'flex', flexDirection: 'column', gap: 22 }}>
          {children}
        </div>
      </section>

      <footer className="site-footer">
        <div className="footer-inner">
          <div className="footer-brand">
            <svg width="20" height="20" viewBox="0 0 32 32" fill="none"><path d="M16 2L28 9V23L16 30L4 23V9L16 2Z" stroke="url(#lg-f)" strokeWidth="2" fill="none"/><path d="M16 10L22 14V22L16 26L10 22V14L16 10Z" fill="url(#lg-f)"/><defs><linearGradient id="lg-f" x1="4" y1="2" x2="28" y2="30"><stop stopColor="#7C3AED"/><stop offset="1" stopColor="#a855f7"/></linearGradient></defs></svg>
            <span>NEXUS CORP TECHNOLOGIES</span>
          </div>
          <p className="footer-copy">2026 NEXUS Corp Technologies. Tous droits réservés.</p>
        </div>
      </footer>
    </div>
  );
}

function H3({ children }: { children: ReactNode }) {
  return <h3 style={{ fontSize: '1.05rem', fontWeight: 700, color: 'var(--text-heading)', marginBottom: 6 }}>{children}</h3>;
}
function P({ children, style }: { children: ReactNode; style?: React.CSSProperties }) {
  return <p style={{ fontSize: '0.9rem', lineHeight: 1.7, color: 'var(--text-muted)', ...style }}>{children}</p>;
}

/** Page Confidentialité. */
export function PrivacyPage() {
  return (
    <InfoLayout kicker="Légal · Confidentialité" title="Politique de confidentialité">
      <div>
        <H3>1. Données que nous collectons</H3>
        <P>Nous collectons les informations que vous nous fournissez lors de la création de votre compte : nom, email, téléphone, pays de résidence, et pour les professionnels, les informations de votre entreprise (forme juridique, numéro d'immatriculation, secteur). Nous collectons également les données d'utilisation (transactions, solides de portefeuille) nécessaires au fonctionnement de la plateforme.</P>
      </div>
      <div>
        <H3>2. Utilisation des données</H3>
        <P>Vos données sont utilisées pour : fournir les services (transferts, conversion, gestion de portefeuille), vérifier votre identité (KYC), prévenir la fraude et le blanchiment d'argent (AML), vous notifier des opérations, et améliorer nos services.</P>
      </div>
      <div>
        <H3>3. Base légale (RGPD)</H3>
        <P>Le traitement de vos données repose sur l'exécution du contrat de service, le respect de nos obligations légales et réglementaires (KYC/AML), votre consentement, et notre intérêt légitime à sécuriser la plateforme.</P>
      </div>
      <div>
        <H3>4. Partage des données</H3>
        <P>Nous ne vendons jamais vos données personnelles. Elles peuvent être partagées avec : nos prestataires de paiement et providers, les autorités compétentes lorsque la loi l'exige, et nos sous-traitants techniques (hébergement, sécurité).</P>
      </div>
      <div>
        <H3>5. Sécurité</H3>
        <P>Vos mots de passe sont hachés, vos données sensibles chiffrées, et nos accès sont contrôlés. Nous appliquons les normes de sécurité du secteur financier (équivalent SOC 2 / PCI-DSS dans l'esprit).</P>
      </div>
      <div>
        <H3>6. Vos droits</H3>
        <P>Conformément au RGPD, vous disposez des droits d'accès, de rectification, d'effacement, de limitation, d'opposition et de portabilité de vos données. Pour les exercer, contactez notre équipe via l'assistance.</P>
      </div>
    </InfoLayout>
  );
}

/** Page Conditions. */
export function TermsPage() {
  return (
    <InfoLayout kicker="Légal · Conditions" title="Conditions générales d'utilisation">
      <div>
        <H3>1. Objet</H3>
        <P>Les présentes conditions régissent l'utilisation de la plateforme Nexus, service d'orchestration financière multi-devises (transferts, conversion, gestion de portefeuille) accessible aux particuliers et aux entreprises.</P>
      </div>
      <div>
        <H3>2. Création de compte</H3>
        <P>Vous devez fournir des informations exactes et complètes. Le compte est personnel et ne peut être cédé. La création d'un compte implique l'acceptation des présentes conditions et de notre politique de confidentialité.</P>
      </div>
      <div>
        <H3>3. Obligations de l'utilisateur</H3>
        <P>Vous vous engagez à : utiliser le service conformément à la loi, ne pas tenter de frauder ou de contourner les dispositifs de sécurité, fournir des documents KYC valides, et préserver la confidentialité de vos identifiants.</P>
      </div>
      <div>
        <H3>4. Vérification KYC / AML</H3>
        <P>Conformément à la réglementation, nous nous réservons le droit de demander des documents d'identité, de suspendre un compte en cas de suspicion de fraude ou de blanchiment, et de refuser certaines opérations.</P>
      </div>
      <div>
        <H3>5. Frais et taux</H3>
        <P>Les frais de chaque opération sont affichés avant confirmation. Les taux de change sont ceux des providers au moment de l'opération. Nexus peut facturer une commission sur certaines opérations, indiquée au préalable.</P>
      </div>
      <div>
        <H3>6. Limitation de responsabilité</H3>
        <P>Nexus met en œuvre les moyens raisonnables pour assurer la continuité du service mais ne saurait être tenu responsable des interruptions liées aux providers, aux réseaux ou à un cas de force majeure.</P>
      </div>
      <div>
        <H3>7. Résiliation</H3>
        <P>Vous pouvez fermer votre compte à tout moment. Nous pouvons suspendre ou clôturer un compte en cas de violation des présentes conditions ou d'exigence réglementaire.</P>
      </div>
    </InfoLayout>
  );
}

/** Page Documentation (API). */
export function DocumentationPage() {
  return (
    <InfoLayout kicker="Développeurs" title="Documentation & API">
      <div>
        <H3>Commencer</H3>
        <P>Nexus expose une API REST pour orchestrer les paiements et conversions multi-providers. L'authentification utilise un jeton JWT. L'environnement de test (sandbox) est disponible pour l'intégration.</P>
      </div>
      <div>
        <H3>Endpoints principaux</H3>
        <P style={{ fontFamily: 'var(--font-mono)', fontSize: '0.82rem' }}>
          POST /api/auth/register<br/>
          POST /api/auth/login<br/>
          GET  /api/wallets<br/>
          GET  /api/wallets/rates<br/>
          POST /api/wallets/convert<br/>
          GET  /api/transfers<br/>
          POST /api/transfers<br/>
        </P>
      </div>
      <div>
        <H3>Authentification</H3>
        <P>Chaque requête protégée doit inclure l'en-tête <code>Authorization: Bearer &lt;token&gt;</code>. Le jeton est délivré lors du login ou de l'inscription.</P>
      </div>
      <div>
        <H3>Environnements</H3>
        <P>Deux environnements sont disponibles : <b>sandbox</b> (tests, fonds fictifs) et <b>production</b> (opérations réelles). Les credentials sont strictement séparés.</P>
      </div>
      <div>
        <H3>Sécurité & conformité</H3>
        <P>Les clés API sont chiffrées et jamais exposées. Toutes les opérations sensibles sont tracées dans un journal d'audit. L'idempotence est supportée pour éviter les doublons de paiement.</P>
      </div>
    </InfoLayout>
  );
}

/** Page Support. */
export function SupportPage() {
  return (
    <InfoLayout kicker="Assistance" title="Centre de support">
      <div>
        <H3>💬 Chat en direct</H3>
        <P>Notre assistant et nos agents sont disponibles directement depuis votre tableau de bord via le bouton de chat (en bas à droite). L'assistant répond instantanément, et un agent humain prend le relais pour les demandes complexes.</P>
      </div>
      <div>
        <H3>🕘 Disponibilité</H3>
        <P>L'assistant automatisé est disponible 24/7. Les agents humains interviennent pendant les heures ouvrées (9h–18h, heure d'Europe centrale), du lundi au vendredi.</P>
      </div>
      <div>
        <H3>❓ Questions fréquentes</H3>
        <P><b>Comment transférer de l'argent ?</b> Allez dans « Envoyer », choisissez la devise et le destinataire, puis confirmez.<br/>
        <b>Quels sont les frais ?</b> Les frais sont affichés avant chaque confirmation d'opération.<br/>
        <b>Comment vérifier mon compte ?</b> Rendez-vous dans « KYC » avec une pièce d'identité et un selfie.</P>
      </div>
      <div>
        <H3>✉️ Contact</H3>
        <P>Pour toute demande, utilisez le chat intégré au tableau de bord. Nos équipes vous répondent sous 24h ouvrées.</P>
      </div>
    </InfoLayout>
  );
}
