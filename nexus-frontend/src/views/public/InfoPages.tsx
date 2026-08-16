import { useEffect, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { ParticlesBackground } from '../../components/ParticlesBackground';
import { LanguageSwitcher } from '../../components/LanguageSwitcher';
import './LandingPage.css';
import './InfoPages.css';

/* ════════════════════════════════════════════════════════════════════════
 * Pages d'information professionnelles (style fintech : Google / Revolut /
 * Wise). Chaque page partage un layout de document avec :
 *   • Barre de navigation sticky (sommaire / TOC) sur la gauche,
 *   • Résumé « en clair » + date de dernière mise à jour en tête,
 *   • Sections scannables (paragraphes courts + puces),
 *   • Badges de confiance & conformité,
 *   • Boutons imprimer / PDF, liens croisés entre politiques.
 * ════════════════════════════════════════════════════════════════════════ */

interface TocItem { id: string; label: string; icon: string; }

/** Layout de document avec TOC sticky + progression de lecture. */
function DocLayout({ kicker, title, updated, toc, children }: {
  kicker: string; title: string; updated: string; toc: TocItem[]; children: ReactNode;
}) {
  const [active, setActive] = useState(toc[0]?.id ?? '');

  // Scroll-spy : met en surbrillance la section visible.
  useEffect(() => {
    const onScroll = () => {
      let current = toc[0]?.id ?? '';
      for (const item of toc) {
        const el = document.getElementById(item.id);
        if (el && el.getBoundingClientRect().top <= 120) current = item.id;
      }
      setActive(current);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, [toc]);

  return (
    <div className="landing-page">
      <ParticlesBackground />
      {/* Navbar */}
      <nav className="site-nav">
        <div className="site-nav-inner">
          <div className="nav-brand">
            <svg width="28" height="28" viewBox="0 0 32 32" fill="none"><path d="M16 2L28 9V23L16 30L4 23V9L16 2Z" stroke="url(#lg-nav)" strokeWidth="2" fill="none"/><path d="M16 10L22 14V22L16 26L10 22V14L16 10Z" fill="url(#lg-nav)"/><defs><linearGradient id="lg-nav" x1="4" y1="2" x2="28" y2="30"><stop stopColor="#7C3AED"/><stop offset="1" stopColor="#a855f7"/></linearGradient></defs></svg>
            <span className="brand-text">NEXUS</span>
          </div>
          <div className="nav-links">
            <Link to="/" className="btn btn-ghost btn-sm" style={{ textDecoration: 'none' }}>← Retour</Link>
            <LanguageSwitcher />
          </div>
        </div>
      </nav>

      <div className="doc-shell">
        {/* ── TOC sticky ── */}
        <aside className="doc-toc" aria-label="Sommaire">
          <div className="doc-toc-inner">
            <div className="doc-toc-title">Sommaire</div>
            {toc.map((item) => (
              <a
                key={item.id} href={`#${item.id}`}
                className={`doc-toc-item ${active === item.id ? 'active' : ''}`}
              >
                <span className="doc-toc-icon">{item.icon}</span>
                <span>{item.label}</span>
              </a>
            ))}
          </div>
        </aside>

        {/* ── Contenu ── */}
        <main className="doc-main">
          {/* En-tête du document */}
          <header className="doc-header">
            <div className="section-kicker">{kicker}</div>
            <h1 className="doc-title">{title}</h1>
            <div className="doc-meta">
              <span className="doc-updated">🕘 Dernière mise à jour : {updated}</span>
              <div className="doc-actions">
                <button className="doc-print" onClick={() => window.print()}>🖨️ Imprimer</button>
              </div>
            </div>
          </header>

          {children}
        </main>
      </div>

      {/* Footer */}
      <footer className="site-footer">
        <div className="footer-inner">
          <div className="footer-brand">
            <svg width="20" height="20" viewBox="0 0 32 32" fill="none"><path d="M16 2L28 9V23L16 30L4 23V9L16 2Z" stroke="url(#lg-f)" strokeWidth="2" fill="none"/><path d="M16 10L22 14V22L16 26L10 22V14L16 10Z" fill="url(#lg-f)"/><defs><linearGradient id="lg-f" x1="4" y1="2" x2="28" y2="30"><stop stopColor="#7C3AED"/><stop offset="1" stopColor="#a855f7"/></linearGradient></defs></svg>
            <span>NEXUS CORP TECHNOLOGIES</span>
          </div>
          <div className="footer-links">
            <Link to="/docs" className="footer-link">Documentation</Link>
            <Link to="/privacy" className="footer-link">Confidentialité</Link>
            <Link to="/terms" className="footer-link">Conditions</Link>
            <Link to="/support" className="footer-link">Support</Link>
          </div>
          <p className="footer-copy">2026 NEXUS Corp Technologies. Tous droits réservés.</p>
        </div>
      </footer>
    </div>
  );
}

/** Badge de confiance / conformité. */
function TrustBadge({ icon, label }: { icon: string; label: string }) {
  return (
    <div className="trust-badge">
      <span className="trust-badge-icon">{icon}</span>
      <span className="trust-badge-label">{label}</span>
    </div>
  );
}

function P({ children, className }: { children: ReactNode; className?: string }) {
  return <p className={`doc-p${className ? ' ' + className : ''}`}>{children}</p>;
}
function Li({ children }: { children: ReactNode }) {
  return <li className="doc-li">{children}</li>;
}

// ==== Confidentialité ====
export function PrivacyPage() {
  const toc: TocItem[] = [
    { id: 'p-collecte', label: 'Données collectées', icon: '📥' },
    { id: 'p-usage', label: 'Utilisation', icon: '⚙️' },
    { id: 'p-partage', label: 'Partage', icon: '🤝' },
    { id: 'p-cookies', label: 'Cookies & suivi', icon: '🍪' },
    { id: 'p-droits', label: 'Vos droits', icon: '🛡️' },
    { id: 'p-securite', label: 'Sécurité', icon: '🔒' },
    { id: 'p-conservation', label: 'Conservation', icon: '🗄️' },
    { id: 'p-contact', label: 'Contact', icon: '✉️' },
  ];
  return (
    <DocLayout kicker="Légal · Confidentialité" title="Politique de confidentialité" updated="16 août 2026" toc={toc}>

      {/* badges de conformité */}
      <div className="doc-trust-row">
        <TrustBadge icon="🛡️" label="RGPD conforme" />
        <TrustBadge icon="🔐" label="Chiffrement AES-256" />
        <TrustBadge icon="🏦" label="KYC / AML" />
        <TrustBadge icon="🌍" label="Données en UE" />
      </div>

      <div className="doc-summary">
        <div className="doc-summary-icon">💡</div>
        <div>
          <div className="doc-summary-title">En résumé</div>
          <div className="doc-summary-text">Nous collectons uniquement les données nécessaires (identité, contacts, transactions) pour vous fournir le service, vérifier votre identité et prévenir la fraude. Nous ne vendons jamais vos données. Vous pouvez accéder, corriger ou supprimer vos informations à tout moment.</div>
        </div>
      </div>

      <div className="doc-section" id="p-collecte">
        <div className="doc-section-head"><span className="doc-section-icon">📥</span><h2>1. Données que nous collectons</h2></div>
        <div className="doc-section-body">
          <P>Nous collectons les informations nécessaires au fonctionnement de la plateforme, et rien de plus :</P>
          <ul className="doc-ul">
            <Li><b>Compte & identité</b> — nom, email, téléphone, pays de résidence, date de naissance.</Li>
            <Li><b>Entreprise (clients pro)</b> — forme juridique, numéro d'immatriculation, secteur, taille.</Li>
            <Li><b>Transactions & portefeuilles</b> — soldes, opérations, devises, taux appliqués.</Li>
            <Li><b>Utilisation & technique</b> — adresse IP, type de navigateur, pages consultées (à des fins de sécurité).</Li>
            <Li><b>Assistance</b> — messages et fichiers que vous nous envoyez via le chat de support.</Li>
          </ul>
          <P className="doc-muted">Nous ne stockons jamais vos numéros de carte en clair : les paiements sont traités par nos providers, et seuls des jetons sécurisés sont conservés.</P>
        </div>
      </div>

      <div className="doc-section" id="p-usage">
        <div className="doc-section-head"><span className="doc-section-icon">⚙️</span><h2>2. Comment nous utilisons vos données</h2></div>
        <div className="doc-section-body">
          <P>Vos données sont traitées sur une base légale claire :</P>
          <ul className="doc-ul">
            <Li><b>Exécution du contrat</b> — fournir les transferts, conversions et la gestion de portefeuille.</Li>
            <Li><b>Obligations légales</b> — vérification KYC, lutte anti-blanchiment (AML), déclarations requises.</Li>
            <Li><b>Intérêt légitime</b> — prévention de la fraude, sécurité des comptes, amélioration du service.</Li>
            <Li><b>Consentement</b> — notifications marketing (uniquement si vous l'acceptez, révocable à tout moment).</Li>
          </ul>
        </div>
      </div>

      <div className="doc-section" id="p-partage">
        <div className="doc-section-head"><span className="doc-section-icon">🤝</span><h2>3. Partage des données</h2></div>
        <div className="doc-section-body">
          <P>Nous ne vendons jamais vos données personnelles. Elles peuvent être partagées uniquement avec :</P>
          <ul className="doc-ul">
            <Li><b>Nos prestataires de paiement et providers</b> — pour exécuter vos opérations.</Li>
            <Li><b>Autorités compétentes</b> — lorsque la loi l'exige (KYC, AML, injonctions judiciaires).</Li>
            <Li><b>Sous-traitants techniques</b> — hébergement, sécurité, support, soumis à des accords de confidentialité.</Li>
          </ul>
        </div>
      </div>

      <div className="doc-section" id="p-cookies">
        <div className="doc-section-head"><span className="doc-section-icon">🍪</span><h2>4. Cookies & suivi</h2></div>
        <div className="doc-section-body">
          <P>Notre site utilise des cookies et le stockage local pour :</P>
          <ul className="doc-ul">
            <Li><b>Cookies essentiels</b> — session, sécurité, préférences (langue, thème).</Li>
            <Li><b>Mesure d'audience</b> — statistiques anonymes de fréquentation.</Li>
          </ul>
          <P className="doc-muted">Vous pouvez désactiver les cookies non essentiels dans les paramètres de votre navigateur. Notre fonctionnalité de session ne repose pas sur des traceurs publicitaires.</P>
        </div>
      </div>

      <div className="doc-section" id="p-droits">
        <div className="doc-section-head"><span className="doc-section-icon">🛡️</span><h2>5. Vos droits (RGPD)</h2></div>
        <div className="doc-section-body">
          <ul className="doc-ul">
            <Li><b>Accès</b> — obtenir une copie de vos données.</Li>
            <Li><b>Rectification</b> — corriger des informations inexactes.</Li>
            <Li><b>Effacement</b> — demander la suppression (sous réserve des obligations KYC/AML).</Li>
            <Li><b>Limitation</b> — restreindre un traitement en cas de litige.</Li>
            <Li><b>Opposition</b> — s'opposer à un traitement fondé sur l'intérêt légitime.</Li>
            <Li><b>Portabilité</b> — recevoir vos données dans un format réutilisable.</Li>
          </ul>
          <P className="doc-note">Pour exercer ces droits, ouvrez un ticket via <Link to="/support" style={{ color: 'var(--cyan)' }}>notre centre de support</Link> ou écrivez-nous à l'adresse indiquée en bas de page. Réponse sous 30 jours.</P>
        </div>
      </div>

      <div className="doc-section" id="p-securite">
        <div className="doc-section-head"><span className="doc-section-icon">🔒</span><h2>6. Sécurité</h2></div>
        <div className="doc-section-body">
          <ul className="doc-ul">
            <Li>Mots de passe <b>hachés</b> (bcrypt), jamais stockés en clair.</Li>
            <Li>Données sensibles <b>chiffrées</b> (AES-256-GCM) au repos.</Li>
            <Li>Connexions <b>TLS</b> et contrôle d'accès basé sur les rôles (RBAC).</Li>
            <Li>Journal d'<b>audit</b> de toutes les opérations sensibles.</Li>
            <Li>Détection de fraude et <b>limitation des tentatives</b> de connexion.</Li>
          </ul>
        </div>
      </div>

      <div className="doc-section" id="p-conservation">
        <div className="doc-section-head"><span className="doc-section-icon">🗄️</span><h2>7. Conservation des données</h2></div>
        <div className="doc-section-body">
          <P>Nous conservons vos données <b>tant que votre compte est actif</b>, puis pendant les délais légaux applicables (notamment les obligations de conservation KYC/AML). À l'issue de ces délais, vos données sont supprimées ou anonymisées.</P>
        </div>
      </div>

      <div className="doc-section" id="p-contact">
        <div className="doc-section-head"><span className="doc-section-icon">✉️</span><h2>8. Contact & réclamations</h2></div>
        <div className="doc-section-body">
          <P><b>Délégué à la protection des données</b> (DPO) : privacy@nexus-tech.io<br/>
          Support client : via le chat intégré au tableau de bord ou support@nexus-tech.io<br/>
          Vous pouvez aussi saisir l'autorité de contrôle compétente (CNIL en France) en cas de litige.</P>
        </div>
      </div>

    </DocLayout>
  );
}

// ==== Conditions ====
export function TermsPage() {
  const toc: TocItem[] = [
    { id: 't-objet', label: 'Objet', icon: '📜' },
    { id: 't-compte', label: 'Compte', icon: '👤' },
    { id: 't-kyc', label: 'KYC / AML', icon: '🪪' },
    { id: 't-services', label: 'Services', icon: '⚙️' },
    { id: 't-frais', label: 'Frais & taux', icon: '🧾' },
    { id: 't-responsabilite', label: 'Responsabilité', icon: '⚖️' },
    { id: 't-resiliation', label: 'Résiliation', icon: '🚪' },
    { id: 't-droit', label: 'Droit applicable', icon: '🏛️' },
  ];
  return (
    <DocLayout kicker="Légal · Conditions" title="Conditions générales d'utilisation" updated="16 août 2026" toc={toc}>

      <div className="doc-summary">
        <div className="doc-summary-icon">💡</div>
        <div>
          <div className="doc-summary-title">En résumé</div>
          <div className="doc-summary-text">En créant un compte Nexus, vous acceptez d'utiliser le service de manière légale et de fournir des informations exactes. Les frais sont toujours affichés avant confirmation. Nous appliquons les règles KYC/AML et pouvons suspendre un compte en cas de fraude.</div>
        </div>
      </div>

      <div className="doc-section" id="t-objet">
        <div className="doc-section-head"><span className="doc-section-icon">📜</span><h2>1. Objet</h2></div>
        <div className="doc-section-body">
          <P>Les présentes conditions régissent l'utilisation de la plateforme Nexus, service d'orchestration financière multi-devises (transferts, conversion, gestion de portefeuille) accessible aux particuliers et aux entreprises.</P>
        </div>
      </div>

      <div className="doc-section" id="t-compte">
        <div className="doc-section-head"><span className="doc-section-icon">👤</span><h2>2. Compte utilisateur</h2></div>
        <div className="doc-section-body">
          <ul className="doc-ul">
            <Li>Vous devez fournir des informations <b>exactes et complètes</b>.</Li>
            <Li>Le compte est <b>personnel</b> et ne peut être cédé ni partagé.</Li>
            <Li>Vous êtes responsable de la <b>confidentialité de vos identifiants</b>.</Li>
            <Li>La création d'un compte vaut <b>acceptation</b> des présentes conditions et de la politique de confidentialité.</Li>
          </ul>
        </div>
      </div>

      <div className="doc-section" id="t-kyc">
        <div className="doc-section-head"><span className="doc-section-icon">🪪</span><h2>3. Vérification KYC / AML</h2></div>
        <div className="doc-section-body">
          <P>Conformément à la réglementation financière, nous pouvons :</P>
          <ul className="doc-ul">
            <Li>Demander des <b>documents d'identité</b> et un selfie.</Li>
            <Li><b>Suspendre</b> un compte en cas de suspicion de fraude ou de blanchiment.</Li>
            <Li><b>Refuser</b> certaines opérations ou le retrait de fonds sans vérification préalable.</Li>
          </ul>
        </div>
      </div>

      <div className="doc-section" id="t-services">
        <div className="doc-section-head"><span className="doc-section-icon">⚙️</span><h2>4. Services</h2></div>
        <div className="doc-section-body">
          <P>Nexus fournit des services de transfert, de conversion de devises et de gestion de portefeuille. Les opérations sont exécutées par des <b>providers tiers</b> ; leurs conditions et disponibilités peuvent varier. Nous mettons en œuvre les moyens raisonnables pour assurer la continuité, sans garantie inconditionnelle de disponibilité.</P>
        </div>
      </div>

      <div className="doc-section" id="t-frais">
        <div className="doc-section-head"><span className="doc-section-icon">🧾</span><h2>5. Frais & taux</h2></div>
        <div className="doc-section-body">
          <ul className="doc-ul">
            <Li>Les <b>frais de chaque opération</b> sont affichés <b>avant confirmation</b>.</Li>
            <Li>Les <b>taux de change</b> sont ceux du provider au moment de l'opération.</Li>
            <Li>Une <b>commission Nexus</b> peut s'appliquer, toujours indiquée au préalable.</Li>
          </ul>
        </div>
      </div>

      <div className="doc-section" id="t-responsabilite">
        <div className="doc-section-head"><span className="doc-section-icon">⚖️</span><h2>6. Limitation de responsabilité</h2></div>
        <div className="doc-section-body">
          <P>Nexus ne saurait être tenu responsable des interruptions ou dommages liés aux providers, aux réseaux, ou à un cas de force majeure. Notre responsabilité est limitée au montant des frais payés sur la période concernée, dans la limite prévue par la loi.</P>
        </div>
      </div>

      <div className="doc-section" id="t-resiliation">
        <div className="doc-section-head"><span className="doc-section-icon">🚪</span><h2>7. Résiliation</h2></div>
        <div className="doc-section-body">
          <P>Vous pouvez <b>fermer votre compte</b> à tout moment via les paramètres ou en contactant le support. Nous pouvons <b>suspendre ou clôturer</b> un compte en cas de violation des présentes conditions ou d'exigence réglementaire, avec notification dans la mesure permise par la loi.</P>
        </div>
      </div>

      <div className="doc-section" id="t-droit">
        <div className="doc-section-head"><span className="doc-section-icon">🏛️</span><h2>8. Droit applicable</h2></div>
        <div className="doc-section-body">
          <P>Les présentes conditions sont soumises au <b>droit français</b>. En cas de litige, les parties chercheront d'abord une solution amiable ; à défaut, les tribunaux compétents seront saisis conformément à la loi.</P>
        </div>
      </div>

    </DocLayout>
  );
}

// ==== Documentation ====
export function DocumentationPage() {
  const toc: TocItem[] = [
    { id: 'd-intro', label: 'Introduction', icon: '🚀' },
    { id: 'd-auth', label: 'Authentification', icon: '🔑' },
    { id: 'd-endpoints', label: 'Endpoints', icon: '🔌' },
    { id: 'd-transfers', label: 'Transferts', icon: '💸' },
    { id: 'd-convert', label: 'Conversion', icon: '🔄' },
    { id: 'd-env', label: 'Environnements', icon: '🌐' },
    { id: 'd-erreurs', label: 'Erreurs', icon: '⚠️' },
    { id: 'd-support', label: 'Support', icon: '✉️' },
  ];
  return (
    <DocLayout kicker="Développeurs · API" title="Documentation & API" updated="16 août 2026" toc={toc}>

      <div className="doc-summary">
        <div className="doc-summary-icon">💡</div>
        <div>
          <div className="doc-summary-title">En résumé</div>
          <div className="doc-summary-text">Nexus expose une API REST pour orchestrer paiements et conversions multi-providers. Authentifiez-vous par jeton JWT, exécutez transferts et conversions, et passez facilement de l'environnement de test à la production.</div>
        </div>
      </div>

      <div className="doc-section" id="d-intro">
        <div className="doc-section-head"><span className="doc-section-icon">🚀</span><h2>1. Introduction</h2></div>
        <div className="doc-section-body">
          <P>L'API Nexus est basée sur <b>HTTP REST</b> et renvoie des réponses JSON. Toutes les requêtes sont préfixées par <code className="doc-code">/api</code>. L'URL de base en développement est <code className="doc-code">http://localhost:8080</code>.</P>
        </div>
      </div>

      <div className="doc-section" id="d-auth">
        <div className="doc-section-head"><span className="doc-section-icon">🔑</span><h2>2. Authentification</h2></div>
        <div className="doc-section-body">
          <P>Créez un compte ou connectez-vous pour obtenir un <b>jeton JWT</b>, puis transmettez-le dans l'en-tête :</P>
          <pre className="doc-codeblock"><code>Authorization: Bearer &lt;votre_token&gt;</code></pre>
          <P>Les endpoints publics (<code className="doc-code">/api/auth/*</code>) ne nécessitent pas de jeton.</P>
        </div>
      </div>

      <div className="doc-section" id="d-endpoints">
        <div className="doc-section-head"><span className="doc-section-icon">🔌</span><h2>3. Endpoints principaux</h2></div>
        <div className="doc-section-body">
          <table className="doc-table">
            <thead><tr><th>Méthode</th><th>Chemin</th><th>Description</th></tr></thead>
            <tbody>
              <tr><td><code className="doc-code">POST</code></td><td><code className="doc-code">/api/auth/register</code></td><td>Créer un compte</td></tr>
              <tr><td><code className="doc-code">POST</code></td><td><code className="doc-code">/api/auth/login</code></td><td>Se connecter</td></tr>
              <tr><td><code className="doc-code">GET</code></td><td><code className="doc-code">/api/wallets</code></td><td>Lister les portefeuilles</td></tr>
              <tr><td><code className="doc-code">GET</code></td><td><code className="doc-code">/api/wallets/rates</code></td><td>Taux de change</td></tr>
              <tr><td><code className="doc-code">POST</code></td><td><code className="doc-code">/api/wallets/convert</code></td><td>Convertir des devises</td></tr>
              <tr><td><code className="doc-code">POST</code></td><td><code className="doc-code">/api/transfers</code></td><td>Céer un transfert</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div className="doc-section" id="d-transfers">
        <div className="doc-section-head"><span className="doc-section-icon">💸</span><h2>4. Transferts</h2></div>
        <div className="doc-section-body">
          <P>Pour exécuter un transfert, créez une requête avec la devise, le montant et le destinataire. Les opérations sont <b>idempotentes</b> : une même clé d'idempotence empêche les doublons.</P>
          <pre className="doc-codeblock"><code>{`POST /api/transfers
{ "currency": "EUR", "amount": 100, "destination": "IBAN..." }`}</code></pre>
        </div>
      </div>

      <div className="doc-section" id="d-convert">
        <div className="doc-section-head"><span className="doc-section-icon">🔄</span><h2>5. Conversion</h2></div>
        <div className="doc-section-body">
          <P>La conversion utilise le taux en vigueur du provider. Le montant converti est calculé au moment de l'exécution, et les frais sont déduits du total.</P>
          <pre className="doc-codeblock"><code>{`POST /api/wallets/convert
{ "from": "EUR", "to": "XAF", "amount": 250 }`}</code></pre>
        </div>
      </div>

      <div className="doc-section" id="d-env">
        <div className="doc-section-head"><span className="doc-section-icon">🌐</span><h2>6. Environnements</h2></div>
        <div className="doc-section-body">
          <ul className="doc-ul">
            <Li><b>Sandbox</b> — tests et intégration, fonds fictifs.</Li>
            <Li><b>Production</b> — opérations réelles, credentials strictement séparés.</Li>
          </ul>
          <P className="doc-note">Les clés API sont chiffrées et jamais exposées côté client. Toutes les opérations sont tracées dans un journal d'audit.</P>
        </div>
      </div>

      <div className="doc-section" id="d-erreurs">
        <div className="doc-section-head"><span className="doc-section-icon">⚠️</span><h2>7. Gestion des erreurs</h2></div>
        <div className="doc-section-body">
          <P>Les erreurs sont renvoyées avec un code HTTP approprié et un corps JSON :</P>
          <pre className="doc-codeblock"><code>{`{ "success": false, "error": "message", "code": "CODE" }`}</code></pre>
          <ul className="doc-ul">
            <Li><b>400</b> — requête invalide · <b>401</b> — non authentifié</Li>
            <Li><b>403</b> — accès refusé · <b>404</b> — introuvable</Li>
            <Li><b>429</b> — trop de requêtes · <b>500</b> — erreur interne</Li>
          </ul>
        </div>
      </div>

      <div className="doc-section" id="d-support">
        <div className="doc-section-head"><span className="doc-section-icon">✉️</span><h2>8. Support développeur</h2></div>
        <div className="doc-section-body">
          <P>Une question sur l'API ? Ouvrez un ticket via <Link to="/support" style={{ color: 'var(--cyan)' }}>notre centre de support</Link> ou écrivez à dev@nexus-tech.io.</P>
        </div>
      </div>

    </DocLayout>
  );
}

// ==== Support ====
export function SupportPage() {
  const toc: TocItem[] = [
    { id: 's-chat', label: 'Chat en direct', icon: '💬' },
    { id: 's-dispo', label: 'Disponibilité', icon: '🕘' },
    { id: 's-faq', label: 'FAQ', icon: '❓' },
    { id: 's-contact', label: 'Contact', icon: '✉️' },
  ];
  return (
    <DocLayout kicker="Assistance" title="Centre de support" updated="16 août 2026" toc={toc}>

      <div className="doc-summary">
        <div className="doc-summary-icon">💡</div>
        <div>
          <div className="doc-summary-title">En résumé</div>
          <div className="doc-summary-text">Notre assistant automatisé est disponible 24/7 dans votre tableau de bord. Pour les demandes complexes, un agent humain prend le relais rapidement. Trouvez aussi des réponses aux questions fréquentes ci-dessous.</div>
        </div>
      </div>

      <div className="doc-section" id="s-chat">
        <div className="doc-section-head"><span className="doc-section-icon">💬</span><h2>1. Chat en direct</h2></div>
        <div className="doc-section-body">
          <P>Le chat est accessible via le <b>bouton en bas à droite</b> de votre tableau de bord. L'assistant répond instantanément aux questions courantes, et un agent humain prend le relais quand nécessaire — le ticket et son historique sont conservés.</P>
        </div>
      </div>

      <div className="doc-section" id="s-dispo">
        <div className="doc-section-head"><span className="doc-section-icon">🕘</span><h2>2. Disponibilité</h2></div>
        <div className="doc-section-body">
          <ul className="doc-ul">
            <Li><b>Assistant automatisé</b> — 24h/24, 7j/7.</Li>
            <Li><b>Agents humains</b> — 9h à 18h (heure Europe centrale), du lundi au vendredi.</Li>
          </ul>
        </div>
      </div>

      <div className="doc-section" id="s-faq">
        <div className="doc-section-head"><span className="doc-section-icon">❓</span><h2>3. Questions fréquentes</h2></div>
        <div className="doc-section-body">
          <P><b>Comment transférer de l'argent ?</b><br/>Allez dans « Envoyer », choisissez la devise et le destinataire, puis confirmez.</P>
          <P><b>Quels sont les frais ?</b><br/>Les frais sont affichés avant chaque confirmation d'opération.</P>
          <P><b>Comment vérifier mon compte ?</b><br/>Rendez-vous dans « KYC » avec une pièce d'identité et un selfie.</P>
          <P><b>Comment changer mon mot de passe ?</b><br/>Dans les paramètres de votre compte, section « Sécurité ».</P>
        </div>
      </div>

      <div className="doc-section" id="s-contact">
        <div className="doc-section-head"><span className="doc-section-icon">✉️</span><h2>4. Contact</h2></div>
        <div className="doc-section-body">
          <P>Utilisez le <b>chat intégré au tableau de bord</b> pour toute demande. Nos équipes vous répondent sous 24h ouvrées. Pour une question commerciale ou presse : contact@nexus-tech.io.</P>
        </div>
      </div>

    </DocLayout>
  );
}
