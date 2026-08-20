import type { LangCode } from './translations';

/**
 * i18n des pages d'information publiques (Documentation, Confidentialité,
 * Conditions, Support) — 7 langues (fr, en, es, pt, de, ar, zh).
 *
 * Contenu structuré (sections + blocs) plutôt que clés plates : les pages
 * sont des documents riches (TOC, listes, code, tableaux). Le rendu est
 * piloté par ces données dans views/public/InfoPages.tsx.
 */

export interface InfoTocItem { id: string; label: string; icon: string; }

export type InfoBlock =
  | { kind: 'p'; text: string; className?: 'muted' | 'note'; }
  | { kind: 'list'; items: Array<{ bold: string; text: string }>; }
  | { kind: 'code'; text: string; }
  | { kind: 'table'; head: string[]; rows: string[][]; }
  | { kind: 'support'; text: string; linkLabel: string; };

export interface InfoSection {
  id: string;
  icon: string;
  heading: string;
  blocks: InfoBlock[];
}

export interface InfoTrust { icon: string; label: string; }

export interface InfoPageContent {
  kicker: string;
  title: string;
  updated: string;
  summaryIcon: string;
  summaryTitle: string;
  summaryText: string;
  trust?: InfoTrust[];
  toc: InfoTocItem[];
  sections: InfoSection[];
}

export interface InfoLayout {
  back: string;
  tocLabel: string;
  updatedLabel: string;
  printLabel: string;
  ariaToc: string;
}

export interface InfoPageSet {
  layout: InfoLayout;
  privacy: InfoPageContent;
  terms: InfoPageContent;
  docs: InfoPageContent;
  support: InfoPageContent;
}

const fr: InfoPageSet = {
  layout: {
    back: '← Retour',
    tocLabel: 'Sommaire',
    updatedLabel: 'Dernière mise à jour',
    printLabel: 'Imprimer',
    ariaToc: 'Sommaire du document',
  },

  // ── Confidentialité ─────────────────────────────────────────────────────
  privacy: {
    kicker: 'Légal · Confidentialité',
    title: 'Politique de confidentialité',
    updated: '16 août 2026',
    summaryIcon: '💡',
    summaryTitle: 'En résumé',
    summaryText: 'Nous collectons uniquement les données nécessaires (identité, contacts, transactions) pour vous fournir le service, vérifier votre identité et prévenir la fraude. Nous ne vendons jamais vos données. Vous pouvez accéder, corriger ou supprimer vos informations à tout moment.',
    trust: [
      { icon: '🛡️', label: 'RGPD conforme' },
      { icon: '🔐', label: 'Chiffrement AES-256' },
      { icon: '🏦', label: 'KYC / AML' },
      { icon: '🌍', label: 'Données en UE' },
    ],
    toc: [
      { id: 'p-collecte', label: 'Données collectées', icon: '📥' },
      { id: 'p-usage', label: 'Utilisation', icon: '⚙️' },
      { id: 'p-partage', label: 'Partage', icon: '🤝' },
      { id: 'p-cookies', label: 'Cookies & suivi', icon: '🍪' },
      { id: 'p-droits', label: 'Vos droits', icon: '🛡️' },
      { id: 'p-securite', label: 'Sécurité', icon: '🔒' },
      { id: 'p-conservation', label: 'Conservation', icon: '🗄️' },
      { id: 'p-contact', label: 'Contact', icon: '✉️' },
    ],
    sections: [
      {
        id: 'p-collecte', icon: '📥', heading: '1. Données que nous collectons',
        blocks: [
          { kind: 'p', text: 'Nous collectons les informations nécessaires au fonctionnement de la plateforme, et rien de plus :' },
          { kind: 'list', items: [
            { bold: 'Compte & identité', text: ' — nom, email, téléphone, pays de résidence, date de naissance.' },
            { bold: 'Entreprise (clients pro)', text: ' — forme juridique, numéro d\'immatriculation, secteur, taille.' },
            { bold: 'Transactions & portefeuilles', text: ' — soldes, opérations, devises, taux appliqués.' },
            { bold: 'Utilisation & technique', text: ' — adresse IP, type de navigateur, pages consultées (à des fins de sécurité).' },
            { bold: 'Assistance', text: ' — messages et fichiers que vous nous envoyez via le chat de support.' },
          ] },
          { kind: 'p', text: 'Nous ne stockons jamais vos numéros de carte en clair : les paiements sont traités par nos providers, et seuls des jetons sécurisés sont conservés.', className: 'muted' },
        ],
      },
      {
        id: 'p-usage', icon: '⚙️', heading: '2. Comment nous utilisons vos données',
        blocks: [
          { kind: 'p', text: 'Vos données sont traitées sur une base légale claire :' },
          { kind: 'list', items: [
            { bold: 'Exécution du contrat', text: ' — fournir les transferts, conversions et la gestion de portefeuille.' },
            { bold: 'Obligations légales', text: ' — vérification KYC, lutte anti-blanchiment (AML), déclarations requises.' },
            { bold: 'Intérêt légitime', text: ' — prévention de la fraude, sécurité des comptes, amélioration du service.' },
            { bold: 'Consentement', text: ' — notifications marketing (uniquement si vous l\'acceptez, révocable à tout moment).' },
          ] },
        ],
      },
      {
        id: 'p-partage', icon: '🤝', heading: '3. Partage des données',
        blocks: [
          { kind: 'p', text: 'Nous ne vendons jamais vos données personnelles. Elles peuvent être partagées uniquement avec :' },
          { kind: 'list', items: [
            { bold: 'Nos prestataires de paiement et providers', text: ' — pour exécuter vos opérations.' },
            { bold: 'Autorités compétentes', text: ' — lorsque la loi l\'exige (KYC, AML, injonctions judiciaires).' },
            { bold: 'Sous-traitants techniques', text: ' — hébergement, sécurité, support, soumis à des accords de confidentialité.' },
          ] },
        ],
      },
      {
        id: 'p-cookies', icon: '🍪', heading: '4. Cookies & suivi',
        blocks: [
          { kind: 'p', text: 'Notre site utilise des cookies et le stockage local pour :' },
          { kind: 'list', items: [
            { bold: 'Cookies essentiels', text: ' — session, sécurité, préférences (langue, thème).' },
            { bold: 'Mesure d\'audience', text: ' — statistiques anonymes de fréquentation.' },
          ] },
          { kind: 'p', text: 'Vous pouvez désactiver les cookies non essentiels dans les paramètres de votre navigateur. Notre session ne repose pas sur des traceurs publicitaires.', className: 'muted' },
        ],
      },
      {
        id: 'p-droits', icon: '🛡️', heading: '5. Vos droits (RGPD)',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Accès', text: ' — obtenir une copie de vos données.' },
            { bold: 'Rectification', text: ' — corriger des informations inexactes.' },
            { bold: 'Effacement', text: ' — demander la suppression (sous réserve des obligations KYC/AML).' },
            { bold: 'Limitation', text: ' — restreindre un traitement en cas de litige.' },
            { bold: 'Opposition', text: ' — s\'opposer à un traitement fondé sur l\'intérêt légitime.' },
            { bold: 'Portabilité', text: ' — recevoir vos données dans un format réutilisable.' },
          ] },
          { kind: 'support', text: 'Pour exercer ces droits, ouvrez un ticket via', linkLabel: 'notre centre de support' },
        ],
      },
      {
        id: 'p-securite', icon: '🔒', heading: '6. Sécurité',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Mots de passe hachés', text: ' (bcrypt), jamais stockés en clair.' },
            { bold: 'Données sensibles chiffrées', text: ' (AES-256-GCM) au repos.' },
            { bold: 'Connexions TLS', text: ' et contrôle d\'accès basé sur les rôles (RBAC).' },
            { bold: 'Journal d\'audit', text: ' de toutes les opérations sensibles.' },
            { bold: 'Détection de fraude', text: ' et limitation des tentatives de connexion.' },
          ] },
        ],
      },
      {
        id: 'p-conservation', icon: '🗄️', heading: '7. Conservation des données',
        blocks: [
          { kind: 'p', text: 'Nous conservons vos données tant que votre compte est actif, puis pendant les délais légaux applicables (notamment les obligations de conservation KYC/AML). À l\'issue de ces délais, vos données sont supprimées ou anonymisées.' },
        ],
      },
      {
        id: 'p-contact', icon: '✉️', heading: '8. Contact & réclamations',
        blocks: [
          { kind: 'p', text: 'Délégué à la protection des données (DPO) : privacy@nexus-tech.io — Support client : via le chat intégré au tableau de bord ou support@nexus-tech.io. Vous pouvez aussi saisir l\'autorité de contrôle compétente (CNIL en France) en cas de litige.' },
        ],
      },
    ],
  },

  // ── Conditions ──────────────────────────────────────────────────────────
  terms: {
    kicker: 'Légal · Conditions',
    title: 'Conditions générales d\'utilisation',
    updated: '16 août 2026',
    summaryIcon: '💡',
    summaryTitle: 'En résumé',
    summaryText: 'En créant un compte Nexus, vous acceptez d\'utiliser le service de manière légale et de fournir des informations exactes. Les frais sont toujours affichés avant confirmation. Nous appliquons les règles KYC/AML et pouvons suspendre un compte en cas de fraude.',
    toc: [
      { id: 't-objet', label: 'Objet', icon: '📜' },
      { id: 't-compte', label: 'Compte', icon: '👤' },
      { id: 't-kyc', label: 'KYC / AML', icon: '🪪' },
      { id: 't-services', label: 'Services', icon: '⚙️' },
      { id: 't-frais', label: 'Frais & taux', icon: '🧾' },
      { id: 't-responsabilite', label: 'Responsabilité', icon: '⚖️' },
      { id: 't-resiliation', label: 'Résiliation', icon: '🚪' },
      { id: 't-droit', label: 'Droit applicable', icon: '🏛️' },
    ],
    sections: [
      {
        id: 't-objet', icon: '📜', heading: '1. Objet',
        blocks: [
          { kind: 'p', text: 'Les présentes conditions régissent l\'utilisation de la plateforme Nexus, service d\'orchestration financière multi-devises (transferts, conversion, gestion de portefeuille) accessible aux particuliers et aux entreprises.' },
        ],
      },
      {
        id: 't-compte', icon: '👤', heading: '2. Compte utilisateur',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Exactitude', text: ' — vous devez fournir des informations exactes et complètes.' },
            { bold: 'Caractère personnel', text: ' — le compte ne peut être cédé ni partagé.' },
            { bold: 'Confidentialité', text: ' — vous êtes responsable de la confidentialité de vos identifiants.' },
            { bold: 'Acceptation', text: ' — la création d\'un compte vaut acceptation des présentes conditions et de la politique de confidentialité.' },
          ] },
        ],
      },
      {
        id: 't-kyc', icon: '🪪', heading: '3. Vérification KYC / AML',
        blocks: [
          { kind: 'p', text: 'Conformément à la réglementation financière, nous pouvons :' },
          { kind: 'list', items: [
            { bold: 'Documents', text: ' — demander des documents d\'identité et un selfie.' },
            { bold: 'Suspension', text: ' — suspendre un compte en cas de suspicion de fraude ou de blanchiment.' },
            { bold: 'Refus', text: ' — refuser certaines opérations ou le retrait de fonds sans vérification préalable.' },
          ] },
        ],
      },
      {
        id: 't-services', icon: '⚙️', heading: '4. Services',
        blocks: [
          { kind: 'p', text: 'Nexus fournit des services de transfert, de conversion de devises et de gestion de portefeuille. Les opérations sont exécutées par des providers tiers ; leurs conditions et disponibilités peuvent varier. Nous mettons en œuvre les moyens raisonnables pour assurer la continuité, sans garantie inconditionnelle de disponibilité.' },
        ],
      },
      {
        id: 't-frais', icon: '🧾', heading: '5. Frais & taux',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Transparence', text: ' — les frais de chaque opération sont affichés avant confirmation.' },
            { bold: 'Taux de change', text: ' — ceux du provider au moment de l\'opération.' },
            { bold: 'Commission Nexus', text: ' — peut s\'appliquer, toujours indiquée au préalable.' },
          ] },
        ],
      },
      {
        id: 't-responsabilite', icon: '⚖️', heading: '6. Limitation de responsabilité',
        blocks: [
          { kind: 'p', text: 'Nexus ne saurait être tenu responsable des interruptions ou dommages liés aux providers, aux réseaux, ou à un cas de force majeure. Notre responsabilité est limitée au montant des frais payés sur la période concernée, dans la limite prévue par la loi.' },
        ],
      },
      {
        id: 't-resiliation', icon: '🚪', heading: '7. Résiliation',
        blocks: [
          { kind: 'p', text: 'Vous pouvez fermer votre compte à tout moment via les paramètres ou en contactant le support. Nous pouvons suspendre ou clôturer un compte en cas de violation des présentes conditions ou d\'exigence réglementaire, avec notification dans la mesure permise par la loi.' },
        ],
      },
      {
        id: 't-droit', icon: '🏛️', heading: '8. Droit applicable',
        blocks: [
          { kind: 'p', text: 'Les présentes conditions sont soumises au droit français. En cas de litige, les parties chercheront d\'abord une solution amiable ; à défaut, les tribunaux compétents seront saisis conformément à la loi.' },
        ],
      },
    ],
  },

  // ── Documentation & API ─────────────────────────────────────────────────
  docs: {
    kicker: 'Développeurs · API',
    title: 'Documentation & API',
    updated: '16 août 2026',
    summaryIcon: '💡',
    summaryTitle: 'En résumé',
    summaryText: 'Nexus expose une API REST pour orchestrer paiements et conversions multi-providers. Authentifiez-vous par jeton JWT, exécutez transferts et conversions, et passez facilement de l\'environnement de test à la production.',
    toc: [
      { id: 'd-intro', label: 'Introduction', icon: '🚀' },
      { id: 'd-auth', label: 'Authentification', icon: '🔑' },
      { id: 'd-endpoints', label: 'Endpoints', icon: '🔌' },
      { id: 'd-transfers', label: 'Transferts', icon: '💸' },
      { id: 'd-convert', label: 'Conversion', icon: '🔄' },
      { id: 'd-env', label: 'Environnements', icon: '🌐' },
      { id: 'd-erreurs', label: 'Erreurs', icon: '⚠️' },
      { id: 'd-support', label: 'Support', icon: '✉️' },
    ],
    sections: [
      {
        id: 'd-intro', icon: '🚀', heading: '1. Introduction',
        blocks: [
          { kind: 'p', text: 'L\'API Nexus est basée sur HTTP REST et renvoie des réponses JSON. Toutes les requêtes sont préfixées par /api. L\'URL de base en développement est http://localhost:8080.' },
        ],
      },
      {
        id: 'd-auth', icon: '🔑', heading: '2. Authentification',
        blocks: [
          { kind: 'p', text: 'Créez un compte ou connectez-vous pour obtenir un jeton JWT, puis transmettez-le dans l\'en-tête :' },
          { kind: 'code', text: 'Authorization: Bearer <votre_token>' },
          { kind: 'p', text: 'Les endpoints publics (/api/auth/*) ne nécessitent pas de jeton.' },
        ],
      },
      {
        id: 'd-endpoints', icon: '🔌', heading: '3. Endpoints principaux',
        blocks: [
          { kind: 'table', head: ['Méthode', 'Chemin', 'Description'], rows: [
            ['POST', '/api/auth/register', 'Créer un compte'],
            ['POST', '/api/auth/login', 'Se connecter'],
            ['GET', '/api/wallets', 'Lister les portefeuilles'],
            ['GET', '/api/wallets/rates', 'Taux de change'],
            ['POST', '/api/wallets/convert', 'Convertir des devises'],
            ['POST', '/api/transfers', 'Créer un transfert'],
          ] },
        ],
      },
      {
        id: 'd-transfers', icon: '💸', heading: '4. Transferts',
        blocks: [
          { kind: 'p', text: 'Pour exécuter un transfert, créez une requête avec la devise, le montant et le destinataire. Les opérations sont idempotentes : une même clé d\'idempotence empêche les doublons.' },
          { kind: 'code', text: 'POST /api/transfers\n{ "currency": "EUR", "amount": 100, "destination": "IBAN..." }' },
        ],
      },
      {
        id: 'd-convert', icon: '🔄', heading: '5. Conversion',
        blocks: [
          { kind: 'p', text: 'La conversion utilise le taux en vigueur du provider. Le montant converti est calculé au moment de l\'exécution, et les frais sont déduits du total.' },
          { kind: 'code', text: 'POST /api/wallets/convert\n{ "from": "EUR", "to": "XAF", "amount": 250 }' },
        ],
      },
      {
        id: 'd-env', icon: '🌐', heading: '6. Environnements',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Sandbox', text: ' — tests et intégration, fonds fictifs.' },
            { bold: 'Production', text: ' — opérations réelles, credentials strictement séparés.' },
          ] },
          { kind: 'p', text: 'Les clés API sont chiffrées et jamais exposées côté client. Toutes les opérations sont tracées dans un journal d\'audit.', className: 'note' },
        ],
      },
      {
        id: 'd-erreurs', icon: '⚠️', heading: '7. Gestion des erreurs',
        blocks: [
          { kind: 'p', text: 'Les erreurs sont renvoyées avec un code HTTP approprié et un corps JSON :' },
          { kind: 'code', text: '{ "success": false, "error": "message", "code": "CODE" }' },
          { kind: 'list', items: [
            { bold: '400', text: ' — requête invalide · 401 — non authentifié' },
            { bold: '403', text: ' — accès refusé · 404 — introuvable' },
            { bold: '429', text: ' — trop de requêtes · 500 — erreur interne' },
          ] },
        ],
      },
      {
        id: 'd-support', icon: '✉️', heading: '8. Support développeur',
        blocks: [
          { kind: 'support', text: 'Une question sur l\'API ? Ouvrez un ticket via', linkLabel: 'notre centre de support' },
        ],
      },
    ],
  },

  // ── Support ─────────────────────────────────────────────────────────────
  support: {
    kicker: 'Assistance',
    title: 'Centre de support',
    updated: '16 août 2026',
    summaryIcon: '💡',
    summaryTitle: 'En résumé',
    summaryText: 'Notre assistant automatisé est disponible 24/7 dans votre tableau de bord. Pour les demandes complexes, un agent humain prend le relais rapidement. Trouvez aussi des réponses aux questions fréquentes ci-dessous.',
    toc: [
      { id: 's-chat', label: 'Chat en direct', icon: '💬' },
      { id: 's-dispo', label: 'Disponibilité', icon: '🕘' },
      { id: 's-faq', label: 'FAQ', icon: '❓' },
      { id: 's-contact', label: 'Contact', icon: '✉️' },
    ],
    sections: [
      {
        id: 's-chat', icon: '💬', heading: '1. Chat en direct',
        blocks: [
          { kind: 'p', text: 'Le chat est accessible via le bouton en bas à droite de votre tableau de bord. L\'assistant répond instantanément aux questions courantes, et un agent humain prend le relais quand nécessaire — le ticket et son historique sont conservés.' },
        ],
      },
      {
        id: 's-dispo', icon: '🕘', heading: '2. Disponibilité',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Assistant automatisé', text: ' — 24h/24, 7j/7.' },
            { bold: 'Agents humains', text: ' — 9h à 18h (heure Europe centrale), du lundi au vendredi.' },
          ] },
        ],
      },
      {
        id: 's-faq', icon: '❓', heading: '3. Questions fréquentes',
        blocks: [
          { kind: 'p', text: 'Comment transférer de l\'argent ? — Allez dans « Envoyer », choisissez la devise et le destinataire, puis confirmez.' },
          { kind: 'p', text: 'Quels sont les frais ? — Les frais sont affichés avant chaque confirmation d\'opération.' },
          { kind: 'p', text: 'Comment vérifier mon compte ? — Rendez-vous dans « KYC » avec une pièce d\'identité et un selfie.' },
          { kind: 'p', text: 'Comment changer mon mot de passe ? — Dans les paramètres de votre compte, section « Sécurité ».' },
        ],
      },
      {
        id: 's-contact', icon: '✉️', heading: '4. Contact',
        blocks: [
          { kind: 'p', text: 'Utilisez le chat intégré au tableau de bord pour toute demande. Nos équipes vous répondent sous 24h ouvrées. Pour une question commerciale ou presse : contact@nexus-tech.io.' },
        ],
      },
    ],
  },
};

// ══════════════════════════════════════════════════════════════════════════
// English
// ══════════════════════════════════════════════════════════════════════════
const en: InfoPageSet = {
  layout: {
    back: '← Back',
    tocLabel: 'Table of contents',
    updatedLabel: 'Last updated',
    printLabel: 'Print',
    ariaToc: 'Table of contents',
  },

  privacy: {
    kicker: 'Legal · Privacy',
    title: 'Privacy Policy',
    updated: 'August 16, 2026',
    summaryIcon: '💡',
    summaryTitle: 'In short',
    summaryText: 'We only collect the data needed (identity, contact details, transactions) to provide the service, verify your identity and prevent fraud. We never sell your data. You can access, correct or delete your information at any time.',
    trust: [
      { icon: '🛡️', label: 'GDPR compliant' },
      { icon: '🔐', label: 'AES-256 encryption' },
      { icon: '🏦', label: 'KYC / AML' },
      { icon: '🌍', label: 'Data hosted in the EU' },
    ],
    toc: [
      { id: 'p-collecte', label: 'Data we collect', icon: '📥' },
      { id: 'p-usage', label: 'How we use it', icon: '⚙️' },
      { id: 'p-partage', label: 'Sharing', icon: '🤝' },
      { id: 'p-cookies', label: 'Cookies & tracking', icon: '🍪' },
      { id: 'p-droits', label: 'Your rights', icon: '🛡️' },
      { id: 'p-securite', label: 'Security', icon: '🔒' },
      { id: 'p-conservation', label: 'Retention', icon: '🗄️' },
      { id: 'p-contact', label: 'Contact', icon: '✉️' },
    ],
    sections: [
      {
        id: 'p-collecte', icon: '📥', heading: '1. Data we collect',
        blocks: [
          { kind: 'p', text: 'We collect the information needed to run the platform — and nothing more:' },
          { kind: 'list', items: [
            { bold: 'Account & identity', text: ' — name, email, phone, country of residence, date of birth.' },
            { bold: 'Business (pro clients)', text: ' — legal form, registration number, industry, size.' },
            { bold: 'Transactions & wallets', text: ' — balances, operations, currencies, applied rates.' },
            { bold: 'Usage & technical', text: ' — IP address, browser type, pages visited (for security purposes).' },
            { bold: 'Support', text: ' — messages and files you send us through the support chat.' },
          ] },
          { kind: 'p', text: 'We never store your card numbers in plain text: payments are processed by our providers, and only secure tokens are kept.', className: 'muted' },
        ],
      },
      {
        id: 'p-usage', icon: '⚙️', heading: '2. How we use your data',
        blocks: [
          { kind: 'p', text: 'Your data is processed on a clear legal basis:' },
          { kind: 'list', items: [
            { bold: 'Contract performance', text: ' — providing transfers, conversions and wallet management.' },
            { bold: 'Legal obligations', text: ' — KYC verification, anti-money laundering (AML), required reporting.' },
            { bold: 'Legitimate interest', text: ' — fraud prevention, account security, service improvement.' },
            { bold: 'Consent', text: ' — marketing notifications (only if you opt in, revocable at any time).' },
          ] },
        ],
      },
      {
        id: 'p-partage', icon: '🤝', heading: '3. Sharing your data',
        blocks: [
          { kind: 'p', text: 'We never sell your personal data. It may only be shared with:' },
          { kind: 'list', items: [
            { bold: 'Our payment providers', text: ' — to execute your operations.' },
            { bold: 'Competent authorities', text: ' — when required by law (KYC, AML, court orders).' },
            { bold: 'Technical sub-processors', text: ' — hosting, security, support, bound by confidentiality agreements.' },
          ] },
        ],
      },
      {
        id: 'p-cookies', icon: '🍪', heading: '4. Cookies & tracking',
        blocks: [
          { kind: 'p', text: 'Our site uses cookies and local storage to:' },
          { kind: 'list', items: [
            { bold: 'Essential cookies', text: ' — session, security, preferences (language, theme).' },
            { bold: 'Audience measurement', text: ' — anonymous usage statistics.' },
          ] },
          { kind: 'p', text: 'You can disable non-essential cookies in your browser settings. Our session does not rely on advertising trackers.', className: 'muted' },
        ],
      },
      {
        id: 'p-droits', icon: '🛡️', heading: '5. Your rights (GDPR)',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Access', text: ' — obtain a copy of your data.' },
            { bold: 'Rectification', text: ' — correct inaccurate information.' },
            { bold: 'Erasure', text: ' — request deletion (subject to KYC/AML obligations).' },
            { bold: 'Restriction', text: ' — limit processing in case of a dispute.' },
            { bold: 'Objection', text: ' — object to processing based on legitimate interest.' },
            { bold: 'Portability', text: ' — receive your data in a reusable format.' },
          ] },
          { kind: 'support', text: 'To exercise these rights, open a ticket via', linkLabel: 'our support center' },
        ],
      },
      {
        id: 'p-securite', icon: '🔒', heading: '6. Security',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Hashed passwords', text: ' (bcrypt), never stored in plain text.' },
            { bold: 'Encrypted sensitive data', text: ' (AES-256-GCM) at rest.' },
            { bold: 'TLS connections', text: ' and role-based access control (RBAC).' },
            { bold: 'Audit log', text: ' of all sensitive operations.' },
            { bold: 'Fraud detection', text: ' and login attempt rate limiting.' },
          ] },
        ],
      },
      {
        id: 'p-conservation', icon: '🗄️', heading: '7. Data retention',
        blocks: [
          { kind: 'p', text: 'We keep your data while your account is active, then for the applicable legal periods (including KYC/AML retention obligations). After these periods, your data is deleted or anonymized.' },
        ],
      },
      {
        id: 'p-contact', icon: '✉️', heading: '8. Contact & complaints',
        blocks: [
          { kind: 'p', text: 'Data Protection Officer (DPO): privacy@nexus-tech.io — Customer support: via the in-dashboard chat or support@nexus-tech.io. You may also contact the competent supervisory authority in case of a dispute.' },
        ],
      },
    ],
  },

  terms: {
    kicker: 'Legal · Terms',
    title: 'Terms of Service',
    updated: 'August 16, 2026',
    summaryIcon: '💡',
    summaryTitle: 'In short',
    summaryText: 'By creating a Nexus account, you agree to use the service lawfully and to provide accurate information. Fees are always displayed before confirmation. We enforce KYC/AML rules and may suspend an account in case of fraud.',
    toc: [
      { id: 't-objet', label: 'Purpose', icon: '📜' },
      { id: 't-compte', label: 'Account', icon: '👤' },
      { id: 't-kyc', label: 'KYC / AML', icon: '🪪' },
      { id: 't-services', label: 'Services', icon: '⚙️' },
      { id: 't-frais', label: 'Fees & rates', icon: '🧾' },
      { id: 't-responsabilite', label: 'Liability', icon: '⚖️' },
      { id: 't-resiliation', label: 'Termination', icon: '🚪' },
      { id: 't-droit', label: 'Governing law', icon: '🏛️' },
    ],
    sections: [
      {
        id: 't-objet', icon: '📜', heading: '1. Purpose',
        blocks: [
          { kind: 'p', text: 'These terms govern the use of the Nexus platform, a multi-currency financial orchestration service (transfers, conversion, wallet management) available to individuals and businesses.' },
        ],
      },
      {
        id: 't-compte', icon: '👤', heading: '2. User account',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Accuracy', text: ' — you must provide accurate and complete information.' },
            { bold: 'Personal use', text: ' — the account cannot be transferred or shared.' },
            { bold: 'Confidentiality', text: ' — you are responsible for keeping your credentials confidential.' },
            { bold: 'Acceptance', text: ' — creating an account constitutes acceptance of these terms and the privacy policy.' },
          ] },
        ],
      },
      {
        id: 't-kyc', icon: '🪪', heading: '3. KYC / AML verification',
        blocks: [
          { kind: 'p', text: 'In accordance with financial regulations, we may:' },
          { kind: 'list', items: [
            { bold: 'Documents', text: ' — request identity documents and a selfie.' },
            { bold: 'Suspension', text: ' — suspend an account in case of suspected fraud or money laundering.' },
            { bold: 'Refusal', text: ' — refuse certain operations or withdrawals without prior verification.' },
          ] },
        ],
      },
      {
        id: 't-services', icon: '⚙️', heading: '4. Services',
        blocks: [
          { kind: 'p', text: 'Nexus provides transfer, currency conversion and wallet management services. Operations are executed by third-party providers; their terms and availability may vary. We take reasonable steps to ensure continuity, without an unconditional availability guarantee.' },
        ],
      },
      {
        id: 't-frais', icon: '🧾', heading: '5. Fees & rates',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Transparency', text: ' — fees for each operation are shown before confirmation.' },
            { bold: 'Exchange rates', text: ' — those of the provider at the time of the operation.' },
            { bold: 'Nexus commission', text: ' — may apply, always disclosed in advance.' },
          ] },
        ],
      },
      {
        id: 't-responsabilite', icon: '⚖️', heading: '6. Limitation of liability',
        blocks: [
          { kind: 'p', text: 'Nexus shall not be liable for interruptions or damages caused by providers, networks, or force majeure. Our liability is limited to the amount of fees paid over the relevant period, within the limits set by law.' },
        ],
      },
      {
        id: 't-resiliation', icon: '🚪', heading: '7. Termination',
        blocks: [
          { kind: 'p', text: 'You can close your account at any time through settings or by contacting support. We may suspend or close an account in case of a breach of these terms or a regulatory requirement, with notice to the extent permitted by law.' },
        ],
      },
      {
        id: 't-droit', icon: '🏛️', heading: '8. Governing law',
        blocks: [
          { kind: 'p', text: 'These terms are governed by French law. In case of a dispute, the parties will first seek an amicable solution; failing that, the competent courts will have jurisdiction in accordance with the law.' },
        ],
      },
    ],
  },

  docs: {
    kicker: 'Developers · API',
    title: 'Documentation & API',
    updated: 'August 16, 2026',
    summaryIcon: '💡',
    summaryTitle: 'In short',
    summaryText: 'Nexus exposes a REST API to orchestrate multi-provider payments and conversions. Authenticate with a JWT token, run transfers and conversions, and move easily from the test environment to production.',
    toc: [
      { id: 'd-intro', label: 'Introduction', icon: '🚀' },
      { id: 'd-auth', label: 'Authentication', icon: '🔑' },
      { id: 'd-endpoints', label: 'Endpoints', icon: '🔌' },
      { id: 'd-transfers', label: 'Transfers', icon: '💸' },
      { id: 'd-convert', label: 'Conversion', icon: '🔄' },
      { id: 'd-env', label: 'Environments', icon: '🌐' },
      { id: 'd-erreurs', label: 'Errors', icon: '⚠️' },
      { id: 'd-support', label: 'Support', icon: '✉️' },
    ],
    sections: [
      {
        id: 'd-intro', icon: '🚀', heading: '1. Introduction',
        blocks: [
          { kind: 'p', text: 'The Nexus API is based on HTTP REST and returns JSON responses. All requests are prefixed with /api. The base URL in development is http://localhost:8080.' },
        ],
      },
      {
        id: 'd-auth', icon: '🔑', heading: '2. Authentication',
        blocks: [
          { kind: 'p', text: 'Create an account or sign in to get a JWT token, then send it in the header:' },
          { kind: 'code', text: 'Authorization: Bearer <your_token>' },
          { kind: 'p', text: 'Public endpoints (/api/auth/*) do not require a token.' },
        ],
      },
      {
        id: 'd-endpoints', icon: '🔌', heading: '3. Main endpoints',
        blocks: [
          { kind: 'table', head: ['Method', 'Path', 'Description'], rows: [
            ['POST', '/api/auth/register', 'Create an account'],
            ['POST', '/api/auth/login', 'Sign in'],
            ['GET', '/api/wallets', 'List wallets'],
            ['GET', '/api/wallets/rates', 'Exchange rates'],
            ['POST', '/api/wallets/convert', 'Convert currencies'],
            ['POST', '/api/transfers', 'Create a transfer'],
          ] },
        ],
      },
      {
        id: 'd-transfers', icon: '💸', heading: '4. Transfers',
        blocks: [
          { kind: 'p', text: 'To run a transfer, create a request with the currency, amount and recipient. Operations are idempotent: the same idempotency key prevents duplicates.' },
          { kind: 'code', text: 'POST /api/transfers\n{ "currency": "EUR", "amount": 100, "destination": "IBAN..." }' },
        ],
      },
      {
        id: 'd-convert', icon: '🔄', heading: '5. Conversion',
        blocks: [
          { kind: 'p', text: 'Conversion uses the provider\'s current rate. The converted amount is calculated at execution time, and fees are deducted from the total.' },
          { kind: 'code', text: 'POST /api/wallets/convert\n{ "from": "EUR", "to": "XAF", "amount": 250 }' },
        ],
      },
      {
        id: 'd-env', icon: '🌐', heading: '6. Environments',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Sandbox', text: ' — testing and integration, fictional funds.' },
            { bold: 'Production', text: ' — real operations, strictly separated credentials.' },
          ] },
          { kind: 'p', text: 'API keys are encrypted and never exposed client-side. All operations are traced in an audit log.', className: 'note' },
        ],
      },
      {
        id: 'd-erreurs', icon: '⚠️', heading: '7. Error handling',
        blocks: [
          { kind: 'p', text: 'Errors are returned with an appropriate HTTP code and a JSON body:' },
          { kind: 'code', text: '{ "success": false, "error": "message", "code": "CODE" }' },
          { kind: 'list', items: [
            { bold: '400', text: ' — invalid request · 401 — unauthenticated' },
            { bold: '403', text: ' — access denied · 404 — not found' },
            { bold: '429', text: ' — too many requests · 500 — internal error' },
          ] },
        ],
      },
      {
        id: 'd-support', icon: '✉️', heading: '8. Developer support',
        blocks: [
          { kind: 'support', text: 'A question about the API? Open a ticket via', linkLabel: 'our support center' },
        ],
      },
    ],
  },

  support: {
    kicker: 'Assistance',
    title: 'Support center',
    updated: 'August 16, 2026',
    summaryIcon: '💡',
    summaryTitle: 'In short',
    summaryText: 'Our automated assistant is available 24/7 in your dashboard. For complex requests, a human agent quickly takes over. You will also find answers to frequently asked questions below.',
    toc: [
      { id: 's-chat', label: 'Live chat', icon: '💬' },
      { id: 's-dispo', label: 'Availability', icon: '🕘' },
      { id: 's-faq', label: 'FAQ', icon: '❓' },
      { id: 's-contact', label: 'Contact', icon: '✉️' },
    ],
    sections: [
      {
        id: 's-chat', icon: '💬', heading: '1. Live chat',
        blocks: [
          { kind: 'p', text: 'The chat is available via the button at the bottom right of your dashboard. The assistant answers common questions instantly, and a human agent takes over when needed — the ticket and its history are kept.' },
        ],
      },
      {
        id: 's-dispo', icon: '🕘', heading: '2. Availability',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Automated assistant', text: ' — 24/7.' },
            { bold: 'Human agents', text: ' — 9 AM to 6 PM (Central European Time), Monday to Friday.' },
          ] },
        ],
      },
      {
        id: 's-faq', icon: '❓', heading: '3. Frequently asked questions',
        blocks: [
          { kind: 'p', text: 'How do I send money? — Go to "Send", choose the currency and recipient, then confirm.' },
          { kind: 'p', text: 'What are the fees? — Fees are shown before each operation is confirmed.' },
          { kind: 'p', text: 'How do I verify my account? — Go to "KYC" with an ID document and a selfie.' },
          { kind: 'p', text: 'How do I change my password? — In your account settings, "Security" section.' },
        ],
      },
      {
        id: 's-contact', icon: '✉️', heading: '4. Contact',
        blocks: [
          { kind: 'p', text: 'Use the in-dashboard chat for any request. Our teams reply within 24 business hours. For commercial or press inquiries: contact@nexus-tech.io.' },
        ],
      },
    ],
  },
};

// ══════════════════════════════════════════════════════════════════════════
// Español
// ══════════════════════════════════════════════════════════════════════════
const es: InfoPageSet = {
  layout: {
    back: '← Volver',
    tocLabel: 'Índice',
    updatedLabel: 'Última actualización',
    printLabel: 'Imprimir',
    ariaToc: 'Índice del documento',
  },

  privacy: {
    kicker: 'Legal · Privacidad',
    title: 'Política de privacidad',
    updated: '16 de agosto de 2026',
    summaryIcon: '💡',
    summaryTitle: 'En resumen',
    summaryText: 'Solo recopilamos los datos necesarios (identidad, contacto, transacciones) para prestar el servicio, verificar su identidad y prevenir el fraude. Nunca vendemos sus datos. Puede acceder, corregir o eliminar su información en cualquier momento.',
    trust: [
      { icon: '🛡️', label: 'Conforme RGPD' },
      { icon: '🔐', label: 'Cifrado AES-256' },
      { icon: '🏦', label: 'KYC / AML' },
      { icon: '🌍', label: 'Datos en la UE' },
    ],
    toc: [
      { id: 'p-collecte', label: 'Datos recopilados', icon: '📥' },
      { id: 'p-usage', label: 'Uso', icon: '⚙️' },
      { id: 'p-partage', label: 'Compartición', icon: '🤝' },
      { id: 'p-cookies', label: 'Cookies y seguimiento', icon: '🍪' },
      { id: 'p-droits', label: 'Sus derechos', icon: '🛡️' },
      { id: 'p-securite', label: 'Seguridad', icon: '🔒' },
      { id: 'p-conservation', label: 'Conservación', icon: '🗄️' },
      { id: 'p-contact', label: 'Contacto', icon: '✉️' },
    ],
    sections: [
      {
        id: 'p-collecte', icon: '📥', heading: '1. Datos que recopilamos',
        blocks: [
          { kind: 'p', text: 'Recopilamos la información necesaria para el funcionamiento de la plataforma, y nada más:' },
          { kind: 'list', items: [
            { bold: 'Cuenta e identidad', text: ' — nombre, correo, teléfono, país de residencia, fecha de nacimiento.' },
            { bold: 'Empresa (clientes pro)', text: ' — forma jurídica, número de registro, sector, tamaño.' },
            { bold: 'Transacciones y carteras', text: ' — saldos, operaciones, divisas, tasas aplicadas.' },
            { bold: 'Uso y técnica', text: ' — dirección IP, tipo de navegador, páginas visitadas (por seguridad).' },
            { bold: 'Asistencia', text: ' — mensajes y archivos que nos envía por el chat de soporte.' },
          ] },
          { kind: 'p', text: 'Nunca almacenamos sus números de tarjeta en claro: los pagos los procesan nuestros proveedores y solo se conservan tokens seguros.', className: 'muted' },
        ],
      },
      {
        id: 'p-usage', icon: '⚙️', heading: '2. Cómo usamos sus datos',
        blocks: [
          { kind: 'p', text: 'Sus datos se tratan sobre una base legal clara:' },
          { kind: 'list', items: [
            { bold: 'Ejecución del contrato', text: ' — prestar transferencias, conversiones y gestión de cartera.' },
            { bold: 'Obligaciones legales', text: ' — verificación KYC, lucha contra el blanqueo (AML), declaraciones requeridas.' },
            { bold: 'Interés legítimo', text: ' — prevención del fraude, seguridad de las cuentas, mejora del servicio.' },
            { bold: 'Consentimiento', text: ' — notificaciones de marketing (solo si las acepta, revocable en cualquier momento).' },
          ] },
        ],
      },
      {
        id: 'p-partage', icon: '🤝', heading: '3. Compartición de datos',
        blocks: [
          { kind: 'p', text: 'Nunca vendemos sus datos personales. Solo pueden compartirse con:' },
          { kind: 'list', items: [
            { bold: 'Nuestros proveedores de pago', text: ' — para ejecutar sus operaciones.' },
            { bold: 'Autoridades competentes', text: ' — cuando la ley lo exija (KYC, AML, órdenes judiciales).' },
            { bold: 'Subcontratistas técnicos', text: ' — alojamiento, seguridad, soporte, sujetos a acuerdos de confidencialidad.' },
          ] },
        ],
      },
      {
        id: 'p-cookies', icon: '🍪', heading: '4. Cookies y seguimiento',
        blocks: [
          { kind: 'p', text: 'Nuestro sitio utiliza cookies y almacenamiento local para:' },
          { kind: 'list', items: [
            { bold: 'Cookies esenciales', text: ' — sesión, seguridad, preferencias (idioma, tema).' },
            { bold: 'Medición de audiencia', text: ' — estadísticas anónimas de uso.' },
          ] },
          { kind: 'p', text: 'Puede desactivar las cookies no esenciales en los ajustes de su navegador. Nuestra sesión no depende de rastreadores publicitarios.', className: 'muted' },
        ],
      },
      {
        id: 'p-droits', icon: '🛡️', heading: '5. Sus derechos (RGPD)',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Acceso', text: ' — obtener una copia de sus datos.' },
            { bold: 'Rectificación', text: ' — corregir información inexacta.' },
            { bold: 'Supresión', text: ' — solicitar la eliminación (sujeto a obligaciones KYC/AML).' },
            { bold: 'Limitación', text: ' — restringir un tratamiento en caso de litigio.' },
            { bold: 'Oposición', text: ' — oponerse a un tratamiento basado en interés legítimo.' },
            { bold: 'Portabilidad', text: ' — recibir sus datos en un formato reutilizable.' },
          ] },
          { kind: 'support', text: 'Para ejercer estos derechos, abra un ticket a través de', linkLabel: 'nuestro centro de soporte' },
        ],
      },
      {
        id: 'p-securite', icon: '🔒', heading: '6. Seguridad',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Contraseñas cifradas', text: ' (bcrypt), nunca almacenadas en claro.' },
            { bold: 'Datos sensibles cifrados', text: ' (AES-256-GCM) en reposo.' },
            { bold: 'Conexiones TLS', text: ' y control de acceso basado en roles (RBAC).' },
            { bold: 'Registro de auditoría', text: ' de todas las operaciones sensibles.' },
            { bold: 'Detección de fraude', text: ' y limitación de intentos de inicio de sesión.' },
          ] },
        ],
      },
      {
        id: 'p-conservation', icon: '🗄️', heading: '7. Conservación de datos',
        blocks: [
          { kind: 'p', text: 'Conservamos sus datos mientras su cuenta esté activa y luego durante los plazos legales aplicables (incluidas las obligaciones de conservación KYC/AML). Tras esos plazos, sus datos se eliminan o anonimizan.' },
        ],
      },
      {
        id: 'p-contact', icon: '✉️', heading: '8. Contacto y reclamaciones',
        blocks: [
          { kind: 'p', text: 'Delegado de protección de datos (DPO): privacy@nexus-tech.io — Soporte al cliente: mediante el chat del panel o support@nexus-tech.io. También puede dirigirse a la autoridad de control competente en caso de litigio.' },
        ],
      },
    ],
  },

  terms: {
    kicker: 'Legal · Condiciones',
    title: 'Condiciones de uso',
    updated: '16 de agosto de 2026',
    summaryIcon: '💡',
    summaryTitle: 'En resumen',
    summaryText: 'Al crear una cuenta Nexus, acepta utilizar el servicio de forma legal y facilitar información exacta. Las comisiones siempre se muestran antes de la confirmación. Aplicamos las normas KYC/AML y podemos suspender una cuenta en caso de fraude.',
    toc: [
      { id: 't-objet', label: 'Objeto', icon: '📜' },
      { id: 't-compte', label: 'Cuenta', icon: '👤' },
      { id: 't-kyc', label: 'KYC / AML', icon: '🪪' },
      { id: 't-services', label: 'Servicios', icon: '⚙️' },
      { id: 't-frais', label: 'Comisiones y tasas', icon: '🧾' },
      { id: 't-responsabilite', label: 'Responsabilidad', icon: '⚖️' },
      { id: 't-resiliation', label: 'Cancelación', icon: '🚪' },
      { id: 't-droit', label: 'Legislación aplicable', icon: '🏛️' },
    ],
    sections: [
      {
        id: 't-objet', icon: '📜', heading: '1. Objeto',
        blocks: [
          { kind: 'p', text: 'Estas condiciones regulan el uso de la plataforma Nexus, un servicio de orquestación financiera multidivisa (transferencias, conversión, gestión de cartera) disponible para particulares y empresas.' },
        ],
      },
      {
        id: 't-compte', icon: '👤', heading: '2. Cuenta de usuario',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Exactitud', text: ' — debe facilitar información exacta y completa.' },
            { bold: 'Uso personal', text: ' — la cuenta no puede cederse ni compartirse.' },
            { bold: 'Confidencialidad', text: ' — es responsable de mantener confidenciales sus credenciales.' },
            { bold: 'Aceptación', text: ' — crear una cuenta implica aceptar estas condiciones y la política de privacidad.' },
          ] },
        ],
      },
      {
        id: 't-kyc', icon: '🪪', heading: '3. Verificación KYC / AML',
        blocks: [
          { kind: 'p', text: 'De conformidad con la normativa financiera, podemos:' },
          { kind: 'list', items: [
            { bold: 'Documentos', text: ' — solicitar documentos de identidad y un selfie.' },
            { bold: 'Suspensión', text: ' — suspender una cuenta ante sospecha de fraude o blanqueo.' },
            { bold: 'Denegación', text: ' — rechazar ciertas operaciones o retiros sin verificación previa.' },
          ] },
        ],
      },
      {
        id: 't-services', icon: '⚙️', heading: '4. Servicios',
        blocks: [
          { kind: 'p', text: 'Nexus presta servicios de transferencia, conversión de divisas y gestión de cartera. Las operaciones las ejecutan proveedores externos; sus condiciones y disponibilidad pueden variar. Tomamos medidas razonables para garantizar la continuidad, sin garantía incondicional de disponibilidad.' },
        ],
      },
      {
        id: 't-frais', icon: '🧾', heading: '5. Comisiones y tasas',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Transparencia', text: ' — las comisiones de cada operación se muestran antes de la confirmación.' },
            { bold: 'Tipos de cambio', text: ' — los del proveedor en el momento de la operación.' },
            { bold: 'Comisión Nexus', text: ' — puede aplicarse, siempre indicada de antemano.' },
          ] },
        ],
      },
      {
        id: 't-responsabilite', icon: '⚖️', heading: '6. Limitación de responsabilidad',
        blocks: [
          { kind: 'p', text: 'Nexus no será responsable de las interrupciones o daños relacionados con proveedores, redes o fuerza mayor. Nuestra responsabilidad se limita al importe de las comisiones pagadas en el período correspondiente, dentro de los límites legales.' },
        ],
      },
      {
        id: 't-resiliation', icon: '🚪', heading: '7. Cancelación',
        blocks: [
          { kind: 'p', text: 'Puede cerrar su cuenta en cualquier momento desde los ajustes o contactando con el soporte. Podemos suspender o cerrar una cuenta en caso de incumplimiento de estas condiciones o por exigencia normativa, con notificación en la medida permitida por la ley.' },
        ],
      },
      {
        id: 't-droit', icon: '🏛️', heading: '8. Legislación aplicable',
        blocks: [
          { kind: 'p', text: 'Estas condiciones se rigen por la legislación francesa. En caso de litigio, las partes buscarán primero una solución amistosa; en su defecto, conocerán los tribunales competentes conforme a la ley.' },
        ],
      },
    ],
  },

  docs: {
    kicker: 'Desarrolladores · API',
    title: 'Documentación y API',
    updated: '16 de agosto de 2026',
    summaryIcon: '💡',
    summaryTitle: 'En resumen',
    summaryText: 'Nexus expone una API REST para orquestar pagos y conversiones multi-proveedor. Autentíquese con un token JWT, ejecute transferencias y conversiones, y pase fácilmente del entorno de prueba a producción.',
    toc: [
      { id: 'd-intro', label: 'Introducción', icon: '🚀' },
      { id: 'd-auth', label: 'Autenticación', icon: '🔑' },
      { id: 'd-endpoints', label: 'Endpoints', icon: '🔌' },
      { id: 'd-transfers', label: 'Transferencias', icon: '💸' },
      { id: 'd-convert', label: 'Conversión', icon: '🔄' },
      { id: 'd-env', label: 'Entornos', icon: '🌐' },
      { id: 'd-erreurs', label: 'Errores', icon: '⚠️' },
      { id: 'd-support', label: 'Soporte', icon: '✉️' },
    ],
    sections: [
      {
        id: 'd-intro', icon: '🚀', heading: '1. Introducción',
        blocks: [
          { kind: 'p', text: 'La API de Nexus se basa en HTTP REST y devuelve respuestas JSON. Todas las solicitudes van precedidas de /api. La URL base en desarrollo es http://localhost:8080.' },
        ],
      },
      {
        id: 'd-auth', icon: '🔑', heading: '2. Autenticación',
        blocks: [
          { kind: 'p', text: 'Cree una cuenta o inicie sesión para obtener un token JWT y envíelo en la cabecera:' },
          { kind: 'code', text: 'Authorization: Bearer <su_token>' },
          { kind: 'p', text: 'Los endpoints públicos (/api/auth/*) no requieren token.' },
        ],
      },
      {
        id: 'd-endpoints', icon: '🔌', heading: '3. Endpoints principales',
        blocks: [
          { kind: 'table', head: ['Método', 'Ruta', 'Descripción'], rows: [
            ['POST', '/api/auth/register', 'Crear una cuenta'],
            ['POST', '/api/auth/login', 'Iniciar sesión'],
            ['GET', '/api/wallets', 'Listar carteras'],
            ['GET', '/api/wallets/rates', 'Tipos de cambio'],
            ['POST', '/api/wallets/convert', 'Convertir divisas'],
            ['POST', '/api/transfers', 'Crear una transferencia'],
          ] },
        ],
      },
      {
        id: 'd-transfers', icon: '💸', heading: '4. Transferencias',
        blocks: [
          { kind: 'p', text: 'Para ejecutar una transferencia, cree una solicitud con la divisa, el importe y el destinatario. Las operaciones son idempotentes: la misma clave de idempotencia evita duplicados.' },
          { kind: 'code', text: 'POST /api/transfers\n{ "currency": "EUR", "amount": 100, "destination": "IBAN..." }' },
        ],
      },
      {
        id: 'd-convert', icon: '🔄', heading: '5. Conversión',
        blocks: [
          { kind: 'p', text: 'La conversión utiliza la tasa vigente del proveedor. El importe convertido se calcula en el momento de la ejecución y las comisiones se deducen del total.' },
          { kind: 'code', text: 'POST /api/wallets/convert\n{ "from": "EUR", "to": "XAF", "amount": 250 }' },
        ],
      },
      {
        id: 'd-env', icon: '🌐', heading: '6. Entornos',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Sandbox', text: ' — pruebas e integración, fondos ficticios.' },
            { bold: 'Producción', text: ' — operaciones reales, credenciales estrictamente separadas.' },
          ] },
          { kind: 'p', text: 'Las claves API están cifradas y nunca se exponen en el cliente. Todas las operaciones quedan registradas en un registro de auditoría.', className: 'note' },
        ],
      },
      {
        id: 'd-erreurs', icon: '⚠️', heading: '7. Gestión de errores',
        blocks: [
          { kind: 'p', text: 'Los errores se devuelven con un código HTTP adecuado y un cuerpo JSON:' },
          { kind: 'code', text: '{ "success": false, "error": "message", "code": "CODE" }' },
          { kind: 'list', items: [
            { bold: '400', text: ' — solicitud no válida · 401 — sin autenticar' },
            { bold: '403', text: ' — acceso denegado · 404 — no encontrado' },
            { bold: '429', text: ' — demasiadas solicitudes · 500 — error interno' },
          ] },
        ],
      },
      {
        id: 'd-support', icon: '✉️', heading: '8. Soporte para desarrolladores',
        blocks: [
          { kind: 'support', text: '¿Una pregunta sobre la API? Abra un ticket a través de', linkLabel: 'nuestro centro de soporte' },
        ],
      },
    ],
  },

  support: {
    kicker: 'Asistencia',
    title: 'Centro de soporte',
    updated: '16 de agosto de 2026',
    summaryIcon: '💡',
    summaryTitle: 'En resumen',
    summaryText: 'Nuestro asistente automatizado está disponible 24/7 en su panel. Para solicitudes complejas, un agente humano toma el relevo rápidamente. También encontrará respuestas a las preguntas frecuentes a continuación.',
    toc: [
      { id: 's-chat', label: 'Chat en directo', icon: '💬' },
      { id: 's-dispo', label: 'Disponibilidad', icon: '🕘' },
      { id: 's-faq', label: 'FAQ', icon: '❓' },
      { id: 's-contact', label: 'Contacto', icon: '✉️' },
    ],
    sections: [
      {
        id: 's-chat', icon: '💬', heading: '1. Chat en directo',
        blocks: [
          { kind: 'p', text: 'El chat está disponible mediante el botón en la parte inferior derecha de su panel. El asistente responde al instante a las preguntas habituales y un agente humano toma el relevo cuando es necesario; el ticket y su historial se conservan.' },
        ],
      },
      {
        id: 's-dispo', icon: '🕘', heading: '2. Disponibilidad',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Asistente automatizado', text: ' — 24/7.' },
            { bold: 'Agentes humanos', text: ' — de 9:00 a 18:00 (hora de Europa central), de lunes a viernes.' },
          ] },
        ],
      },
      {
        id: 's-faq', icon: '❓', heading: '3. Preguntas frecuentes',
        blocks: [
          { kind: 'p', text: '¿Cómo envío dinero? — Vaya a «Enviar», elija la divisa y el destinatario, y confirme.' },
          { kind: 'p', text: '¿Cuáles son las comisiones? — Las comisiones se muestran antes de cada confirmación de operación.' },
          { kind: 'p', text: '¿Cómo verifico mi cuenta? — Vaya a «KYC» con un documento de identidad y un selfie.' },
          { kind: 'p', text: '¿Cómo cambio mi contraseña? — En los ajustes de su cuenta, sección «Seguridad».' },
        ],
      },
      {
        id: 's-contact', icon: '✉️', heading: '4. Contacto',
        blocks: [
          { kind: 'p', text: 'Utilice el chat integrado en el panel para cualquier solicitud. Nuestros equipos responden en menos de 24 horas laborables. Para consultas comerciales o de prensa: contact@nexus-tech.io.' },
        ],
      },
    ],
  },
};

// ══════════════════════════════════════════════════════════════════════════
// Português
// ══════════════════════════════════════════════════════════════════════════
const pt: InfoPageSet = {
  layout: {
    back: '← Voltar',
    tocLabel: 'Índice',
    updatedLabel: 'Última atualização',
    printLabel: 'Imprimir',
    ariaToc: 'Índice do documento',
  },

  privacy: {
    kicker: 'Jurídico · Privacidade',
    title: 'Política de privacidade',
    updated: '16 de agosto de 2026',
    summaryIcon: '💡',
    summaryTitle: 'Em resumo',
    summaryText: 'Coletamos apenas os dados necessários (identidade, contatos, transações) para prestar o serviço, verificar sua identidade e prevenir fraudes. Nunca vendemos seus dados. Você pode acessar, corrigir ou excluir suas informações a qualquer momento.',
    trust: [
      { icon: '🛡️', label: 'Conforme RGPD' },
      { icon: '🔐', label: 'Criptografia AES-256' },
      { icon: '🏦', label: 'KYC / AML' },
      { icon: '🌍', label: 'Dados na UE' },
    ],
    toc: [
      { id: 'p-collecte', label: 'Dados coletados', icon: '📥' },
      { id: 'p-usage', label: 'Utilização', icon: '⚙️' },
      { id: 'p-partage', label: 'Partilha', icon: '🤝' },
      { id: 'p-cookies', label: 'Cookies e rastreio', icon: '🍪' },
      { id: 'p-droits', label: 'Os seus direitos', icon: '🛡️' },
      { id: 'p-securite', label: 'Segurança', icon: '🔒' },
      { id: 'p-conservation', label: 'Conservação', icon: '🗄️' },
      { id: 'p-contact', label: 'Contacto', icon: '✉️' },
    ],
    sections: [
      {
        id: 'p-collecte', icon: '📥', heading: '1. Dados que coletamos',
        blocks: [
          { kind: 'p', text: 'Coletamos as informações necessárias ao funcionamento da plataforma, e nada mais:' },
          { kind: 'list', items: [
            { bold: 'Conta e identidade', text: ' — nome, e-mail, telefone, país de residência, data de nascimento.' },
            { bold: 'Empresa (clientes pro)', text: ' — forma jurídica, número de registo, setor, dimensão.' },
            { bold: 'Transações e carteiras', text: ' — saldos, operações, moedas, taxas aplicadas.' },
            { bold: 'Utilização e técnica', text: ' — endereço IP, tipo de navegador, páginas visitadas (para segurança).' },
            { bold: 'Assistência', text: ' — mensagens e ficheiros que nos envia através do chat de suporte.' },
          ] },
          { kind: 'p', text: 'Nunca armazenamos os seus números de cartão em texto simples: os pagamentos são processados pelos nossos providers e apenas são guardados tokens seguros.', className: 'muted' },
        ],
      },
      {
        id: 'p-usage', icon: '⚙️', heading: '2. Como usamos os seus dados',
        blocks: [
          { kind: 'p', text: 'Os seus dados são tratados com base legal clara:' },
          { kind: 'list', items: [
            { bold: 'Execução do contrato', text: ' — prestar transferências, conversões e gestão de carteira.' },
            { bold: 'Obrigações legais', text: ' — verificação KYC, combate ao branqueamento (AML), declarações exigidas.' },
            { bold: 'Interesse legítimo', text: ' — prevenção de fraude, segurança das contas, melhoria do serviço.' },
            { bold: 'Consentimento', text: ' — notificações de marketing (apenas se aceitar, revogável a qualquer momento).' },
          ] },
        ],
      },
      {
        id: 'p-partage', icon: '🤝', heading: '3. Partilha de dados',
        blocks: [
          { kind: 'p', text: 'Nunca vendemos os seus dados pessoais. Só podem ser partilhados com:' },
          { kind: 'list', items: [
            { bold: 'Os nossos providers de pagamento', text: ' — para executar as suas operações.' },
            { bold: 'Autoridades competentes', text: ' — quando a lei o exigir (KYC, AML, ordens judiciais).' },
            { bold: 'Subcontratados técnicos', text: ' — alojamento, segurança, suporte, sujeitos a acordos de confidencialidade.' },
          ] },
        ],
      },
      {
        id: 'p-cookies', icon: '🍪', heading: '4. Cookies e rastreio',
        blocks: [
          { kind: 'p', text: 'O nosso site utiliza cookies e armazenamento local para:' },
          { kind: 'list', items: [
            { bold: 'Cookies essenciais', text: ' — sessão, segurança, preferências (idioma, tema).' },
            { bold: 'Medição de audiência', text: ' — estatísticas anónimas de utilização.' },
          ] },
          { kind: 'p', text: 'Pode desativar os cookies não essenciais nas definições do seu navegador. A nossa sessão não depende de rastreadores publicitários.', className: 'muted' },
        ],
      },
      {
        id: 'p-droits', icon: '🛡️', heading: '5. Os seus direitos (RGPD)',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Acesso', text: ' — obter uma cópia dos seus dados.' },
            { bold: 'Retificação', text: ' — corrigir informações inexatas.' },
            { bold: 'Eliminação', text: ' — solicitar a supressão (sujeito às obrigações KYC/AML).' },
            { bold: 'Limitação', text: ' — restringir um tratamento em caso de litígio.' },
            { bold: 'Oposição', text: ' — opor-se a um tratamento baseado em interesse legítimo.' },
            { bold: 'Portabilidade', text: ' — receber os seus dados num formato reutilizável.' },
          ] },
          { kind: 'support', text: 'Para exercer estes direitos, abra um ticket através do', linkLabel: 'nosso centro de suporte' },
        ],
      },
      {
        id: 'p-securite', icon: '🔒', heading: '6. Segurança',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Palavras-passe cifradas', text: ' (bcrypt), nunca armazenadas em texto simples.' },
            { bold: 'Dados sensíveis cifrados', text: ' (AES-256-GCM) em repouso.' },
            { bold: 'Ligações TLS', text: ' e controlo de acesso baseado em funções (RBAC).' },
            { bold: 'Registo de auditoria', text: ' de todas as operações sensíveis.' },
            { bold: 'Deteção de fraude', text: ' e limitação de tentativas de início de sessão.' },
          ] },
        ],
      },
      {
        id: 'p-conservation', icon: '🗄️', heading: '7. Conservação de dados',
        blocks: [
          { kind: 'p', text: 'Conservamos os seus dados enquanto a sua conta estiver ativa e, depois, durante os prazos legais aplicáveis (incluindo as obrigações de conservação KYC/AML). Após esses prazos, os seus dados são eliminados ou anonimizados.' },
        ],
      },
      {
        id: 'p-contact', icon: '✉️', heading: '8. Contacto e reclamações',
        blocks: [
          { kind: 'p', text: 'Encarregado da proteção de dados (DPO): privacy@nexus-tech.io — Apoio ao cliente: através do chat do painel ou support@nexus-tech.io. Também pode recorrer à autoridade de controlo competente em caso de litígio.' },
        ],
      },
    ],
  },

  terms: {
    kicker: 'Jurídico · Condições',
    title: 'Condições de utilização',
    updated: '16 de agosto de 2026',
    summaryIcon: '💡',
    summaryTitle: 'Em resumo',
    summaryText: 'Ao criar uma conta Nexus, aceita utilizar o serviço de forma legal e fornecer informações exatas. As taxas são sempre apresentadas antes da confirmação. Aplicamos as regras KYC/AML e podemos suspender uma conta em caso de fraude.',
    toc: [
      { id: 't-objet', label: 'Objeto', icon: '📜' },
      { id: 't-compte', label: 'Conta', icon: '👤' },
      { id: 't-kyc', label: 'KYC / AML', icon: '🪪' },
      { id: 't-services', label: 'Serviços', icon: '⚙️' },
      { id: 't-frais', label: 'Taxas e câmbios', icon: '🧾' },
      { id: 't-responsabilite', label: 'Responsabilidade', icon: '⚖️' },
      { id: 't-resiliation', label: 'Rescisão', icon: '🚪' },
      { id: 't-droit', label: 'Legislação aplicável', icon: '🏛️' },
    ],
    sections: [
      {
        id: 't-objet', icon: '📜', heading: '1. Objeto',
        blocks: [
          { kind: 'p', text: 'Estas condições regem a utilização da plataforma Nexus, um serviço de orquestração financeira multimoeda (transferências, conversão, gestão de carteira) disponível para particulares e empresas.' },
        ],
      },
      {
        id: 't-compte', icon: '👤', heading: '2. Conta de utilizador',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Exatidão', text: ' — deve fornecer informações exatas e completas.' },
            { bold: 'Uso pessoal', text: ' — a conta não pode ser cedida nem partilhada.' },
            { bold: 'Confidencialidade', text: ' — é responsável pela confidencialidade das suas credenciais.' },
            { bold: 'Aceitação', text: ' — criar uma conta implica aceitar estas condições e a política de privacidade.' },
          ] },
        ],
      },
      {
        id: 't-kyc', icon: '🪪', heading: '3. Verificação KYC / AML',
        blocks: [
          { kind: 'p', text: 'Em conformidade com a regulamentação financeira, podemos:' },
          { kind: 'list', items: [
            { bold: 'Documentos', text: ' — solicitar documentos de identidade e um selfie.' },
            { bold: 'Suspensão', text: ' — suspender uma conta em caso de suspeita de fraude ou branqueamento.' },
            { bold: 'Recusa', text: ' — recusar certas operações ou levantamentos sem verificação prévia.' },
          ] },
        ],
      },
      {
        id: 't-services', icon: '⚙️', heading: '4. Serviços',
        blocks: [
          { kind: 'p', text: 'A Nexus presta serviços de transferência, conversão de moedas e gestão de carteira. As operações são executadas por providers terceiros; as suas condições e disponibilidade podem variar. Tomamos medidas razoáveis para garantir a continuidade, sem garantia incondicional de disponibilidade.' },
        ],
      },
      {
        id: 't-frais', icon: '🧾', heading: '5. Taxas e câmbios',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Transparência', text: ' — as taxas de cada operação são apresentadas antes da confirmação.' },
            { bold: 'Taxas de câmbio', text: ' — as do provider no momento da operação.' },
            { bold: 'Comissão Nexus', text: ' — pode ser aplicada, sempre indicada previamente.' },
          ] },
        ],
      },
      {
        id: 't-responsabilite', icon: '⚖️', heading: '6. Limitação de responsabilidade',
        blocks: [
          { kind: 'p', text: 'A Nexus não será responsável por interrupções ou danos relacionados com providers, redes ou força maior. A nossa responsabilidade limita-se ao valor das taxas pagas no período em causa, dentro dos limites previstos na lei.' },
        ],
      },
      {
        id: 't-resiliation', icon: '🚪', heading: '7. Rescisão',
        blocks: [
          { kind: 'p', text: 'Pode encerrar a sua conta a qualquer momento através das definições ou contactando o suporte. Podemos suspender ou encerrar uma conta em caso de violação destas condições ou por exigência regulamentar, com notificação na medida permitida pela lei.' },
        ],
      },
      {
        id: 't-droit', icon: '🏛️', heading: '8. Legislação aplicável',
        blocks: [
          { kind: 'p', text: 'Estas condições regem-se pelo direito francês. Em caso de litígio, as partes procurarão primeiro uma solução amigável; na falta dela, serão competentes os tribunais competentes, nos termos da lei.' },
        ],
      },
    ],
  },

  docs: {
    kicker: 'Programadores · API',
    title: 'Documentação e API',
    updated: '16 de agosto de 2026',
    summaryIcon: '💡',
    summaryTitle: 'Em resumo',
    summaryText: 'A Nexus expõe uma API REST para orquestrar pagamentos e conversões multi-provider. Autentique-se com um token JWT, execute transferências e conversões e passe facilmente do ambiente de teste para a produção.',
    toc: [
      { id: 'd-intro', label: 'Introdução', icon: '🚀' },
      { id: 'd-auth', label: 'Autenticação', icon: '🔑' },
      { id: 'd-endpoints', label: 'Endpoints', icon: '🔌' },
      { id: 'd-transfers', label: 'Transferências', icon: '💸' },
      { id: 'd-convert', label: 'Conversão', icon: '🔄' },
      { id: 'd-env', label: 'Ambientes', icon: '🌐' },
      { id: 'd-erreurs', label: 'Erros', icon: '⚠️' },
      { id: 'd-support', label: 'Suporte', icon: '✉️' },
    ],
    sections: [
      {
        id: 'd-intro', icon: '🚀', heading: '1. Introdução',
        blocks: [
          { kind: 'p', text: 'A API da Nexus baseia-se em HTTP REST e devolve respostas JSON. Todos os pedidos são prefixados com /api. O URL base em desenvolvimento é http://localhost:8080.' },
        ],
      },
      {
        id: 'd-auth', icon: '🔑', heading: '2. Autenticação',
        blocks: [
          { kind: 'p', text: 'Crie uma conta ou inicie sessão para obter um token JWT e envie-o no cabeçalho:' },
          { kind: 'code', text: 'Authorization: Bearer <o_seu_token>' },
          { kind: 'p', text: 'Os endpoints públicos (/api/auth/*) não requerem token.' },
        ],
      },
      {
        id: 'd-endpoints', icon: '🔌', heading: '3. Endpoints principais',
        blocks: [
          { kind: 'table', head: ['Método', 'Caminho', 'Descrição'], rows: [
            ['POST', '/api/auth/register', 'Criar uma conta'],
            ['POST', '/api/auth/login', 'Iniciar sessão'],
            ['GET', '/api/wallets', 'Listar carteiras'],
            ['GET', '/api/wallets/rates', 'Taxas de câmbio'],
            ['POST', '/api/wallets/convert', 'Converter moedas'],
            ['POST', '/api/transfers', 'Criar uma transferência'],
          ] },
        ],
      },
      {
        id: 'd-transfers', icon: '💸', heading: '4. Transferências',
        blocks: [
          { kind: 'p', text: 'Para executar uma transferência, crie um pedido com a moeda, o montante e o destinatário. As operações são idempotentes: a mesma chave de idempotência evita duplicados.' },
          { kind: 'code', text: 'POST /api/transfers\n{ "currency": "EUR", "amount": 100, "destination": "IBAN..." }' },
        ],
      },
      {
        id: 'd-convert', icon: '🔄', heading: '5. Conversão',
        blocks: [
          { kind: 'p', text: 'A conversão utiliza a taxa em vigor do provider. O montante convertido é calculado no momento da execução e as taxas são deduzidas do total.' },
          { kind: 'code', text: 'POST /api/wallets/convert\n{ "from": "EUR", "to": "XAF", "amount": 250 }' },
        ],
      },
      {
        id: 'd-env', icon: '🌐', heading: '6. Ambientes',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Sandbox', text: ' — testes e integração, fundos fictícios.' },
            { bold: 'Produção', text: ' — operações reais, credenciais estritamente separadas.' },
          ] },
          { kind: 'p', text: 'As chaves API estão cifradas e nunca são expostas no cliente. Todas as operações ficam registadas num registo de auditoria.', className: 'note' },
        ],
      },
      {
        id: 'd-erreurs', icon: '⚠️', heading: '7. Gestão de erros',
        blocks: [
          { kind: 'p', text: 'Os erros são devolvidos com um código HTTP adequado e um corpo JSON:' },
          { kind: 'code', text: '{ "success": false, "error": "message", "code": "CODE" }' },
          { kind: 'list', items: [
            { bold: '400', text: ' — pedido inválido · 401 — não autenticado' },
            { bold: '403', text: ' — acesso negado · 404 — não encontrado' },
            { bold: '429', text: ' — demasiados pedidos · 500 — erro interno' },
          ] },
        ],
      },
      {
        id: 'd-support', icon: '✉️', heading: '8. Suporte para programadores',
        blocks: [
          { kind: 'support', text: 'Uma pergunta sobre a API? Abra um ticket através do', linkLabel: 'nosso centro de suporte' },
        ],
      },
    ],
  },

  support: {
    kicker: 'Assistência',
    title: 'Centro de suporte',
    updated: '16 de agosto de 2026',
    summaryIcon: '💡',
    summaryTitle: 'Em resumo',
    summaryText: 'O nosso assistente automatizado está disponível 24/7 no seu painel. Para pedidos complexos, um agente humano assume rapidamente. Encontre também respostas às perguntas frequentes abaixo.',
    toc: [
      { id: 's-chat', label: 'Chat em direto', icon: '💬' },
      { id: 's-dispo', label: 'Disponibilidade', icon: '🕘' },
      { id: 's-faq', label: 'FAQ', icon: '❓' },
      { id: 's-contact', label: 'Contacto', icon: '✉️' },
    ],
    sections: [
      {
        id: 's-chat', icon: '💬', heading: '1. Chat em direto',
        blocks: [
          { kind: 'p', text: 'O chat está disponível através do botão no canto inferior direito do seu painel. O assistente responde instantaneamente às perguntas habituais e um agente humano assume quando necessário — o ticket e o seu histórico são conservados.' },
        ],
      },
      {
        id: 's-dispo', icon: '🕘', heading: '2. Disponibilidade',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Assistente automatizado', text: ' — 24 horas por dia, 7 dias por semana.' },
            { bold: 'Agentes humanos', text: ' — das 9h às 18h (hora da Europa Central), de segunda a sexta.' },
          ] },
        ],
      },
      {
        id: 's-faq', icon: '❓', heading: '3. Perguntas frequentes',
        blocks: [
          { kind: 'p', text: 'Como envio dinheiro? — Vá a «Enviar», escolha a moeda e o destinatário e confirme.' },
          { kind: 'p', text: 'Quais são as taxas? — As taxas são apresentadas antes de cada confirmação de operação.' },
          { kind: 'p', text: 'Como verifico a minha conta? — Vá a «KYC» com um documento de identificação e um selfie.' },
          { kind: 'p', text: 'Como mudo a minha palavra-passe? — Nas definições da sua conta, secção «Segurança».' },
        ],
      },
      {
        id: 's-contact', icon: '✉️', heading: '4. Contacto',
        blocks: [
          { kind: 'p', text: 'Utilize o chat integrado no painel para qualquer pedido. As nossas equipas respondem em menos de 24 horas úteis. Para questões comerciais ou de imprensa: contact@nexus-tech.io.' },
        ],
      },
    ],
  },
};

// ══════════════════════════════════════════════════════════════════════════
// Deutsch
// ══════════════════════════════════════════════════════════════════════════
const de: InfoPageSet = {
  layout: {
    back: '← Zurück',
    tocLabel: 'Inhaltsverzeichnis',
    updatedLabel: 'Zuletzt aktualisiert',
    printLabel: 'Drucken',
    ariaToc: 'Inhaltsverzeichnis',
  },

  privacy: {
    kicker: 'Rechtliches · Datenschutz',
    title: 'Datenschutzerklärung',
    updated: '16. August 2026',
    summaryIcon: '💡',
    summaryTitle: 'Kurz gesagt',
    summaryText: 'Wir erheben nur die Daten, die zur Bereitstellung des Dienstes, zur Identitätsprüfung und zur Betrugsprävention erforderlich sind (Identität, Kontaktdaten, Transaktionen). Wir verkaufen Ihre Daten niemals. Sie können Ihre Informationen jederzeit einsehen, korrigieren oder löschen.',
    trust: [
      { icon: '🛡️', label: 'DSGVO-konform' },
      { icon: '🔐', label: 'AES-256-Verschlüsselung' },
      { icon: '🏦', label: 'KYC / AML' },
      { icon: '🌍', label: 'Daten in der EU' },
    ],
    toc: [
      { id: 'p-collecte', label: 'Erhobene Daten', icon: '📥' },
      { id: 'p-usage', label: 'Verwendung', icon: '⚙️' },
      { id: 'p-partage', label: 'Weitergabe', icon: '🤝' },
      { id: 'p-cookies', label: 'Cookies & Tracking', icon: '🍪' },
      { id: 'p-droits', label: 'Ihre Rechte', icon: '🛡️' },
      { id: 'p-securite', label: 'Sicherheit', icon: '🔒' },
      { id: 'p-conservation', label: 'Speicherdauer', icon: '🗄️' },
      { id: 'p-contact', label: 'Kontakt', icon: '✉️' },
    ],
    sections: [
      {
        id: 'p-collecte', icon: '📥', heading: '1. Daten, die wir erheben',
        blocks: [
          { kind: 'p', text: 'Wir erheben nur die Informationen, die für den Betrieb der Plattform erforderlich sind — und nichts darüber hinaus:' },
          { kind: 'list', items: [
            { bold: 'Konto & Identität', text: ' — Name, E-Mail, Telefon, Wohnsitzland, Geburtsdatum.' },
            { bold: 'Unternehmen (Geschäftskunden)', text: ' — Rechtsform, Registrierungsnummer, Branche, Größe.' },
            { bold: 'Transaktionen & Wallets', text: ' — Salden, Operationen, Währungen, angewandte Kurse.' },
            { bold: 'Nutzung & Technik', text: ' — IP-Adresse, Browsertyp, besuchte Seiten (zu Sicherheitszwecken).' },
            { bold: 'Support', text: ' — Nachrichten und Dateien, die Sie uns über den Support-Chat senden.' },
          ] },
          { kind: 'p', text: 'Wir speichern Ihre Kartennummern niemals im Klartext: Zahlungen werden von unseren Providern verarbeitet, und es werden nur sichere Token aufbewahrt.', className: 'muted' },
        ],
      },
      {
        id: 'p-usage', icon: '⚙️', heading: '2. Wie wir Ihre Daten verwenden',
        blocks: [
          { kind: 'p', text: 'Ihre Daten werden auf einer klaren Rechtsgrundlage verarbeitet:' },
          { kind: 'list', items: [
            { bold: 'Vertragserfüllung', text: ' — Bereitstellung von Überweisungen, Konvertierungen und Wallet-Verwaltung.' },
            { bold: 'Gesetzliche Verpflichtungen', text: ' — KYC-Prüfung, Bekämpfung der Geldwäsche (AML), erforderliche Meldungen.' },
            { bold: 'Berechtigtes Interesse', text: ' — Betrugsprävention, Kontosicherheit, Verbesserung des Dienstes.' },
            { bold: 'Einwilligung', text: ' — Marketing-Benachrichtigungen (nur bei Zustimmung, jederzeit widerrufbar).' },
          ] },
        ],
      },
      {
        id: 'p-partage', icon: '🤝', heading: '3. Weitergabe von Daten',
        blocks: [
          { kind: 'p', text: 'Wir verkaufen Ihre personenbezogenen Daten niemals. Sie dürfen nur geteilt werden mit:' },
          { kind: 'list', items: [
            { bold: 'Unseren Zahlungsdienstleistern', text: ' — zur Ausführung Ihrer Operationen.' },
            { bold: 'Zuständigen Behörden', text: ' — wenn es das Gesetz verlangt (KYC, AML, Gerichtsbeschlüsse).' },
            { bold: 'Technischen Auftragsverarbeitern', text: ' — Hosting, Sicherheit, Support, vertraglich zur Vertraulichkeit verpflichtet.' },
          ] },
        ],
      },
      {
        id: 'p-cookies', icon: '🍪', heading: '4. Cookies & Tracking',
        blocks: [
          { kind: 'p', text: 'Unsere Website verwendet Cookies und lokalen Speicher für:' },
          { kind: 'list', items: [
            { bold: 'Notwendige Cookies', text: ' — Sitzung, Sicherheit, Einstellungen (Sprache, Design).' },
            { bold: 'Reichweitenmessung', text: ' — anonyme Nutzungsstatistiken.' },
          ] },
          { kind: 'p', text: 'Sie können nicht notwendige Cookies in Ihren Browsereinstellungen deaktivieren. Unsere Sitzung beruht nicht auf Werbe-Trackern.', className: 'muted' },
        ],
      },
      {
        id: 'p-droits', icon: '🛡️', heading: '5. Ihre Rechte (DSGVO)',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Auskunft', text: ' — eine Kopie Ihrer Daten erhalten.' },
            { bold: 'Berichtigung', text: ' — unrichtige Angaben korrigieren.' },
            { bold: 'Löschung', text: ' — Löschung verlangen (vorbehaltlich KYC/AML-Pflichten).' },
            { bold: 'Einschränkung', text: ' — Verarbeitung bei Streitigkeiten einschränken.' },
            { bold: 'Widerspruch', text: ' — der Verarbeitung auf Grundlage berechtigten Interesses widersprechen.' },
            { bold: 'Datenübertragbarkeit', text: ' — Ihre Daten in einem wiederverwendbaren Format erhalten.' },
          ] },
          { kind: 'support', text: 'Um diese Rechte auszuüben, eröffnen Sie ein Ticket über', linkLabel: 'unser Support-Center' },
        ],
      },
      {
        id: 'p-securite', icon: '🔒', heading: '6. Sicherheit',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Gehashte Passwörter', text: ' (bcrypt), niemals im Klartext gespeichert.' },
            { bold: 'Verschlüsselte sensible Daten', text: ' (AES-256-GCM) im Ruhezustand.' },
            { bold: 'TLS-Verbindungen', text: ' und rollenbasierte Zugriffskontrolle (RBAC).' },
            { bold: 'Prüfprotokoll', text: ' aller sensiblen Operationen.' },
            { bold: 'Betrugserkennung', text: ' und Begrenzung der Anmeldeversuche.' },
          ] },
        ],
      },
      {
        id: 'p-conservation', icon: '🗄️', heading: '7. Speicherdauer',
        blocks: [
          { kind: 'p', text: 'Wir speichern Ihre Daten, solange Ihr Konto aktiv ist, und danach für die geltenden gesetzlichen Fristen (einschließlich der KYC/AML-Aufbewahrungspflichten). Nach Ablauf dieser Fristen werden Ihre Daten gelöscht oder anonymisiert.' },
        ],
      },
      {
        id: 'p-contact', icon: '✉️', heading: '8. Kontakt & Beschwerden',
        blocks: [
          { kind: 'p', text: 'Datenschutzbeauftragter (DSB): privacy@nexus-tech.io — Kundensupport: über den integrierten Chat oder support@nexus-tech.io. Bei Streitigkeiten können Sie sich auch an die zuständige Aufsichtsbehörde wenden.' },
        ],
      },
    ],
  },

  terms: {
    kicker: 'Rechtliches · Bedingungen',
    title: 'Nutzungsbedingungen',
    updated: '16. August 2026',
    summaryIcon: '💡',
    summaryTitle: 'Kurz gesagt',
    summaryText: 'Mit der Erstellung eines Nexus-Kontos verpflichten Sie sich, den Dienst rechtmäßig zu nutzen und korrekte Angaben zu machen. Gebühren werden immer vor der Bestätigung angezeigt. Wir wenden KYC/AML-Regeln an und können ein Konto bei Betrug sperren.',
    toc: [
      { id: 't-objet', label: 'Gegenstand', icon: '📜' },
      { id: 't-compte', label: 'Konto', icon: '👤' },
      { id: 't-kyc', label: 'KYC / AML', icon: '🪪' },
      { id: 't-services', label: 'Dienste', icon: '⚙️' },
      { id: 't-frais', label: 'Gebühren & Kurse', icon: '🧾' },
      { id: 't-responsabilite', label: 'Haftung', icon: '⚖️' },
      { id: 't-resiliation', label: 'Kündigung', icon: '🚪' },
      { id: 't-droit', label: 'Anwendbares Recht', icon: '🏛️' },
    ],
    sections: [
      {
        id: 't-objet', icon: '📜', heading: '1. Gegenstand',
        blocks: [
          { kind: 'p', text: 'Diese Bedingungen regeln die Nutzung der Nexus-Plattform, eines mehrwährungsfähigen Finanzorchestrierungsdienstes (Überweisungen, Konvertierung, Wallet-Verwaltung) für Privatpersonen und Unternehmen.' },
        ],
      },
      {
        id: 't-compte', icon: '👤', heading: '2. Benutzerkonto',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Richtigkeit', text: ' — Sie müssen korrekte und vollständige Angaben machen.' },
            { bold: 'Persönliche Nutzung', text: ' — das Konto kann weder übertragen noch geteilt werden.' },
            { bold: 'Vertraulichkeit', text: ' — Sie sind für die Vertraulichkeit Ihrer Zugangsdaten verantwortlich.' },
            { bold: 'Annahme', text: ' — mit der Kontoerstellung akzeptieren Sie diese Bedingungen und die Datenschutzerklärung.' },
          ] },
        ],
      },
      {
        id: 't-kyc', icon: '🪪', heading: '3. KYC / AML-Verifizierung',
        blocks: [
          { kind: 'p', text: 'Gemäß den Finanzvorschriften können wir:' },
          { kind: 'list', items: [
            { bold: 'Dokumente', text: ' — Identitätsdokumente und ein Selfie verlangen.' },
            { bold: 'Sperrung', text: ' — ein Konto bei Verdacht auf Betrug oder Geldwäsche sperren.' },
            { bold: 'Ablehnung', text: ' — bestimmte Operationen oder Abhebungen ohne vorherige Prüfung ablehnen.' },
          ] },
        ],
      },
      {
        id: 't-services', icon: '⚙️', heading: '4. Dienste',
        blocks: [
          { kind: 'p', text: 'Nexus bietet Überweisungs-, Währungsumrechnungs- und Wallet-Verwaltungsdienste an. Operationen werden von Drittanbietern ausgeführt; deren Bedingungen und Verfügbarkeit können variieren. Wir ergreifen angemessene Maßnahmen zur Sicherstellung der Kontinuität, ohne bedingungslose Verfügbarkeitsgarantie.' },
        ],
      },
      {
        id: 't-frais', icon: '🧾', heading: '5. Gebühren & Kurse',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Transparenz', text: ' — die Gebühren jeder Operation werden vor der Bestätigung angezeigt.' },
            { bold: 'Wechselkurse', text: ' — die des Providers zum Zeitpunkt der Operation.' },
            { bold: 'Nexus-Kommission', text: ' — kann anfallen, immer vorher angegeben.' },
          ] },
        ],
      },
      {
        id: 't-responsabilite', icon: '⚖️', heading: '6. Haftungsbeschränkung',
        blocks: [
          { kind: 'p', text: 'Nexus haftet nicht für Unterbrechungen oder Schäden, die durch Provider, Netzwerke oder höhere Gewalt verursacht werden. Unsere Haftung ist auf die im betreffenden Zeitraum gezahlten Gebühren begrenzt, innerhalb der gesetzlichen Grenzen.' },
        ],
      },
      {
        id: 't-resiliation', icon: '🚪', heading: '7. Kündigung',
        blocks: [
          { kind: 'p', text: 'Sie können Ihr Konto jederzeit über die Einstellungen oder durch Kontaktaufnahme mit dem Support schließen. Wir können ein Konto bei Verstoß gegen diese Bedingungen oder aus regulatorischen Gründen sperren oder schließen, mit Benachrichtigung im gesetzlich zulässigen Rahmen.' },
        ],
      },
      {
        id: 't-droit', icon: '🏛️', heading: '8. Anwendbares Recht',
        blocks: [
          { kind: 'p', text: 'Diese Bedingungen unterliegen französischem Recht. Im Streitfall bemühen sich die Parteien zunächst um eine gütliche Einigung; andernfalls sind die zuständigen Gerichte gemäß Gesetz angerufen.' },
        ],
      },
    ],
  },

  docs: {
    kicker: 'Entwickler · API',
    title: 'Dokumentation & API',
    updated: '16. August 2026',
    summaryIcon: '💡',
    summaryTitle: 'Kurz gesagt',
    summaryText: 'Nexus stellt eine REST-API zur Orchestrierung von Multi-Provider-Zahlungen und -Konvertierungen bereit. Authentifizieren Sie sich mit einem JWT-Token, führen Sie Überweisungen und Konvertierungen aus und wechseln Sie einfach von der Testumgebung in die Produktion.',
    toc: [
      { id: 'd-intro', label: 'Einführung', icon: '🚀' },
      { id: 'd-auth', label: 'Authentifizierung', icon: '🔑' },
      { id: 'd-endpoints', label: 'Endpunkte', icon: '🔌' },
      { id: 'd-transfers', label: 'Überweisungen', icon: '💸' },
      { id: 'd-convert', label: 'Konvertierung', icon: '🔄' },
      { id: 'd-env', label: 'Umgebungen', icon: '🌐' },
      { id: 'd-erreurs', label: 'Fehler', icon: '⚠️' },
      { id: 'd-support', label: 'Support', icon: '✉️' },
    ],
    sections: [
      {
        id: 'd-intro', icon: '🚀', heading: '1. Einführung',
        blocks: [
          { kind: 'p', text: 'Die Nexus-API basiert auf HTTP REST und liefert JSON-Antworten. Alle Anfragen sind mit /api versehen. Die Basis-URL in der Entwicklung ist http://localhost:8080.' },
        ],
      },
      {
        id: 'd-auth', icon: '🔑', heading: '2. Authentifizierung',
        blocks: [
          { kind: 'p', text: 'Erstellen Sie ein Konto oder melden Sie sich an, um ein JWT-Token zu erhalten, und senden Sie es im Header:' },
          { kind: 'code', text: 'Authorization: Bearer <ihr_token>' },
          { kind: 'p', text: 'Öffentliche Endpunkte (/api/auth/*) benötigen kein Token.' },
        ],
      },
      {
        id: 'd-endpoints', icon: '🔌', heading: '3. Haupt-Endpunkte',
        blocks: [
          { kind: 'table', head: ['Methode', 'Pfad', 'Beschreibung'], rows: [
            ['POST', '/api/auth/register', 'Konto erstellen'],
            ['POST', '/api/auth/login', 'Anmelden'],
            ['GET', '/api/wallets', 'Wallets auflisten'],
            ['GET', '/api/wallets/rates', 'Wechselkurse'],
            ['POST', '/api/wallets/convert', 'Währungen umrechnen'],
            ['POST', '/api/transfers', 'Überweisung erstellen'],
          ] },
        ],
      },
      {
        id: 'd-transfers', icon: '💸', heading: '4. Überweisungen',
        blocks: [
          { kind: 'p', text: 'Um eine Überweisung auszuführen, erstellen Sie eine Anfrage mit Währung, Betrag und Empfänger. Operationen sind idempotent: derselbe Idempotenzschlüssel verhindert Duplikate.' },
          { kind: 'code', text: 'POST /api/transfers\n{ "currency": "EUR", "amount": 100, "destination": "IBAN..." }' },
        ],
      },
      {
        id: 'd-convert', icon: '🔄', heading: '5. Konvertierung',
        blocks: [
          { kind: 'p', text: 'Die Konvertierung verwendet den aktuellen Kurs des Providers. Der umgerechnete Betrag wird zum Zeitpunkt der Ausführung berechnet, und Gebühren werden vom Gesamtbetrag abgezogen.' },
          { kind: 'code', text: 'POST /api/wallets/convert\n{ "from": "EUR", "to": "XAF", "amount": 250 }' },
        ],
      },
      {
        id: 'd-env', icon: '🌐', heading: '6. Umgebungen',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Sandbox', text: ' — Tests und Integration, fiktive Gelder.' },
            { bold: 'Produktion', text: ' — echte Operationen, streng getrennte Zugangsdaten.' },
          ] },
          { kind: 'p', text: 'API-Schlüssel sind verschlüsselt und werden niemals clientseitig offengelegt. Alle Operationen werden in einem Prüfprotokoll erfasst.', className: 'note' },
        ],
      },
      {
        id: 'd-erreurs', icon: '⚠️', heading: '7. Fehlerbehandlung',
        blocks: [
          { kind: 'p', text: 'Fehler werden mit einem passenden HTTP-Code und einem JSON-Body zurückgegeben:' },
          { kind: 'code', text: '{ "success": false, "error": "message", "code": "CODE" }' },
          { kind: 'list', items: [
            { bold: '400', text: ' — ungültige Anfrage · 401 — nicht authentifiziert' },
            { bold: '403', text: ' — Zugriff verweigert · 404 — nicht gefunden' },
            { bold: '429', text: ' — zu viele Anfragen · 500 — interner Fehler' },
          ] },
        ],
      },
      {
        id: 'd-support', icon: '✉️', heading: '8. Entwickler-Support',
        blocks: [
          { kind: 'support', text: 'Eine Frage zur API? Eröffnen Sie ein Ticket über', linkLabel: 'unser Support-Center' },
        ],
      },
    ],
  },

  support: {
    kicker: 'Hilfe',
    title: 'Support-Center',
    updated: '16. August 2026',
    summaryIcon: '💡',
    summaryTitle: 'Kurz gesagt',
    summaryText: 'Unser automatisierter Assistent ist rund um die Uhr in Ihrem Dashboard verfügbar. Bei komplexen Anfragen übernimmt schnell ein menschlicher Agent. Antworten auf häufige Fragen finden Sie unten.',
    toc: [
      { id: 's-chat', label: 'Live-Chat', icon: '💬' },
      { id: 's-dispo', label: 'Verfügbarkeit', icon: '🕘' },
      { id: 's-faq', label: 'FAQ', icon: '❓' },
      { id: 's-contact', label: 'Kontakt', icon: '✉️' },
    ],
    sections: [
      {
        id: 's-chat', icon: '💬', heading: '1. Live-Chat',
        blocks: [
          { kind: 'p', text: 'Der Chat ist über die Schaltfläche unten rechts in Ihrem Dashboard erreichbar. Der Assistent beantwortet häufige Fragen sofort, und ein menschlicher Agent übernimmt bei Bedarf — das Ticket und sein Verlauf bleiben erhalten.' },
        ],
      },
      {
        id: 's-dispo', icon: '🕘', heading: '2. Verfügbarkeit',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Automatisierter Assistent', text: ' — 24/7.' },
            { bold: 'Menschliche Agenten', text: ' — 9 bis 18 Uhr (Mitteleuropäische Zeit), Montag bis Freitag.' },
          ] },
        ],
      },
      {
        id: 's-faq', icon: '❓', heading: '3. Häufige Fragen',
        blocks: [
          { kind: 'p', text: 'Wie sende ich Geld? — Gehen Sie zu „Senden“, wählen Sie Währung und Empfänger und bestätigen Sie.' },
          { kind: 'p', text: 'Wie hoch sind die Gebühren? — Gebühren werden vor jeder Bestätigung angezeigt.' },
          { kind: 'p', text: 'Wie verifiziere ich mein Konto? — Gehen Sie zu „KYC“ mit einem Ausweisdokument und einem Selfie.' },
          { kind: 'p', text: 'Wie ändere ich mein Passwort? — In den Kontoeinstellungen, Abschnitt „Sicherheit“.' },
        ],
      },
      {
        id: 's-contact', icon: '✉️', heading: '4. Kontakt',
        blocks: [
          { kind: 'p', text: 'Nutzen Sie den integrierten Chat im Dashboard für jede Anfrage. Unsere Teams antworten innerhalb von 24 Werktagen. Für geschäftliche oder Presseanfragen: contact@nexus-tech.io.' },
        ],
      },
    ],
  },
};

// ══════════════════════════════════════════════════════════════════════════
// العربية (RTL)
// ══════════════════════════════════════════════════════════════════════════
const ar: InfoPageSet = {
  layout: {
    back: '→ رجوع',
    tocLabel: 'فهرس المحتويات',
    updatedLabel: 'آخر تحديث',
    printLabel: 'طباعة',
    ariaToc: 'فهرس المحتويات',
  },

  privacy: {
    kicker: 'قانوني · الخصوصية',
    title: 'سياسة الخصوصية',
    updated: '16 أغسطس 2026',
    summaryIcon: '💡',
    summaryTitle: 'باختصار',
    summaryText: 'نجمع فقط البيانات اللازمة (الهوية، بيانات الاتصال، المعاملات) لتقديم الخدمة والتحقق من هويتك ومنع الاحتيال. لا نبيع بياناتك أبدًا. يمكنك الوصول إلى معلوماتك أو تصحيحها أو حذفها في أي وقت.',
    trust: [
      { icon: '🛡️', label: 'متوافق مع RGPD' },
      { icon: '🔐', label: 'تشفير AES-256' },
      { icon: '🏦', label: 'KYC / AML' },
      { icon: '🌍', label: 'البيانات في الاتحاد الأوروبي' },
    ],
    toc: [
      { id: 'p-collecte', label: 'البيانات التي نجمعها', icon: '📥' },
      { id: 'p-usage', label: 'الاستخدام', icon: '⚙️' },
      { id: 'p-partage', label: 'المشاركة', icon: '🤝' },
      { id: 'p-cookies', label: 'ملفات الارتباط والتتبع', icon: '🍪' },
      { id: 'p-droits', label: 'حقوقك', icon: '🛡️' },
      { id: 'p-securite', label: 'الأمان', icon: '🔒' },
      { id: 'p-conservation', label: 'الاحتفاظ', icon: '🗄️' },
      { id: 'p-contact', label: 'الاتصال', icon: '✉️' },
    ],
    sections: [
      {
        id: 'p-collecte', icon: '📥', heading: '1. البيانات التي نجمعها',
        blocks: [
          { kind: 'p', text: 'نجمع المعلومات اللازمة لتشغيل المنصة، ولا شيء أكثر من ذلك:' },
          { kind: 'list', items: [
            { bold: 'الحساب والهوية', text: ' — الاسم، البريد الإلكتروني، الهاتف، بلد الإقامة، تاريخ الميلاد.' },
            { bold: 'الشركة (عملاء الأعمال)', text: ' — الشكل القانوني، رقم التسجيل، القطاع، الحجم.' },
            { bold: 'المعاملات والمحافظ', text: ' — الأرصدة، العمليات، العملات، الأسعار المطبقة.' },
            { bold: 'الاستخدام والتقنية', text: ' — عنوان IP، نوع المتصفح، الصفحات المزارة (لأغراض أمنية).' },
            { bold: 'الدعم', text: ' — الرسائل والملفات التي ترسلها عبر دردشة الدعم.' },
          ] },
          { kind: 'p', text: 'لا نخزن أرقام بطاقاتك أبدًا كنص صريح: تتم معالجة المدفوعات بواسطة مزودينا، ولا نحتفظ سوى برموز آمنة.', className: 'muted' },
        ],
      },
      {
        id: 'p-usage', icon: '⚙️', heading: '2. كيف نستخدم بياناتك',
        blocks: [
          { kind: 'p', text: 'تتم معالجة بياناتك على أساس قانوني واضح:' },
          { kind: 'list', items: [
            { bold: 'تنفيذ العقد', text: ' — تقديم التحويلات والتحويلات النقدية وإدارة المحفظة.' },
            { bold: 'الالتزامات القانونية', text: ' — التحقق من KYC، مكافحة غسل الأموال (AML)، التقارير المطلوبة.' },
            { bold: 'المصلحة المشروعة', text: ' — منع الاحتيال، أمان الحسابات، تحسين الخدمة.' },
            { bold: 'الموافقة', text: ' — إشعارات التسويق (فقط إذا وافقت، ويمكن إلغاؤها في أي وقت).' },
          ] },
        ],
      },
      {
        id: 'p-partage', icon: '🤝', heading: '3. مشاركة البيانات',
        blocks: [
          { kind: 'p', text: 'لا نبيع بياناتك الشخصية أبدًا. لا يمكن مشاركتها إلا مع:' },
          { kind: 'list', items: [
            { bold: 'مزودي الدفع لدينا', text: ' — لتنفيذ عملياتك.' },
            { bold: 'السلطات المختصة', text: ' — عندما يقتضي القانون ذلك (KYC، AML، أوامر قضائية).' },
            { bold: 'المعالجون التقنيون', text: ' — الاستضافة، الأمان، الدعم، بموجب اتفاقيات سرية.' },
          ] },
        ],
      },
      {
        id: 'p-cookies', icon: '🍪', heading: '4. ملفات الارتباط والتتبع',
        blocks: [
          { kind: 'p', text: 'يستخدم موقعنا ملفات الارتباط والتخزين المحلي من أجل:' },
          { kind: 'list', items: [
            { bold: 'ملفات ارتباط أساسية', text: ' — الجلسة، الأمان، التفضيلات (اللغة، المظهر).' },
            { bold: 'قياس الجمهور', text: ' — إحصائيات استخدام مجهولة.' },
          ] },
          { kind: 'p', text: 'يمكنك تعطيل ملفات الارتباط غير الأساسية في إعدادات المتصفح. لا تعتمد جلستنا على أدوات تتبع إعلانية.', className: 'muted' },
        ],
      },
      {
        id: 'p-droits', icon: '🛡️', heading: '5. حقوقك (RGPD)',
        blocks: [
          { kind: 'list', items: [
            { bold: 'الوصول', text: ' — الحصول على نسخة من بياناتك.' },
            { bold: 'التصحيح', text: ' — تصحيح المعلومات غير الدقيقة.' },
            { bold: 'المحو', text: ' — طلب الحذف (مع مراعاة التزامات KYC/AML).' },
            { bold: 'التقييد', text: ' — تقييد المعالجة في حالة النزاع.' },
            { bold: 'الاعتراض', text: ' — الاعتراض على معالجة تستند إلى مصلحة مشروعة.' },
            { bold: 'قابلية النقل', text: ' — استلام بياناتك بتنسيق قابل لإعادة الاستخدام.' },
          ] },
          { kind: 'support', text: 'لممارسة هذه الحقوق، افتح تذكرة عبر', linkLabel: 'مركز الدعم لدينا' },
        ],
      },
      {
        id: 'p-securite', icon: '🔒', heading: '6. الأمان',
        blocks: [
          { kind: 'list', items: [
            { bold: 'كلمات مرور مشفرة', text: ' (bcrypt)، لا تُخزن أبدًا كنص صريح.' },
            { bold: 'بيانات حساسة مشفرة', text: ' (AES-256-GCM) في حالة السكون.' },
            { bold: 'اتصالات TLS', text: ' والتحكم في الوصول القائم على الأدوار (RBAC).' },
            { bold: 'سجل تدقيق', text: ' لجميع العمليات الحساسة.' },
            { bold: 'كشف الاحتيال', text: ' والحد من محاولات تسجيل الدخول.' },
          ] },
        ],
      },
      {
        id: 'p-conservation', icon: '🗄️', heading: '7. الاحتفاظ بالبيانات',
        blocks: [
          { kind: 'p', text: 'نحتفظ ببياناتك طالما كان حسابك نشطًا، ثم خلال الفترات القانونية المطبقة (بما في ذلك التزامات الاحتفاظ بـ KYC/AML). بعد هذه الفترات، تُحذف بياناتك أو تُجعل مجهولة الهوية.' },
        ],
      },
      {
        id: 'p-contact', icon: '✉️', heading: '8. الاتصال والشكاوى',
        blocks: [
          { kind: 'p', text: 'مسؤول حماية البيانات (DPO): privacy@nexus-tech.io — دعم العملاء: عبر الدردشة داخل لوحة التحكم أو support@nexus-tech.io. يمكنك أيضًا التواصل مع سلطة الرقابة المختصة في حالة النزاع.' },
        ],
      },
    ],
  },

  terms: {
    kicker: 'قانوني · الشروط',
    title: 'شروط الاستخدام',
    updated: '16 أغسطس 2026',
    summaryIcon: '💡',
    summaryTitle: 'باختصار',
    summaryText: 'بإنشاء حساب Nexus، فإنك توافق على استخدام الخدمة بشكل قانوني وتقديم معلومات دقيقة. تُعرض الرسوم دائمًا قبل التأكيد. نطبق قواعد KYC/AML وقد نعلق حسابًا في حالة الاحتيال.',
    toc: [
      { id: 't-objet', label: 'الغرض', icon: '📜' },
      { id: 't-compte', label: 'الحساب', icon: '👤' },
      { id: 't-kyc', label: 'KYC / AML', icon: '🪪' },
      { id: 't-services', label: 'الخدمات', icon: '⚙️' },
      { id: 't-frais', label: 'الرسوم والأسعار', icon: '🧾' },
      { id: 't-responsabilite', label: 'المسؤولية', icon: '⚖️' },
      { id: 't-resiliation', label: 'الإنهاء', icon: '🚪' },
      { id: 't-droit', label: 'القانون المطبق', icon: '🏛️' },
    ],
    sections: [
      {
        id: 't-objet', icon: '📜', heading: '1. الغرض',
        blocks: [
          { kind: 'p', text: 'تنظم هذه الشروط استخدام منصة Nexus، وهي خدمة تنسيق مالي متعدد العملات (تحويلات، تحويل نقدي، إدارة محفظة) متاحة للأفراد والشركات.' },
        ],
      },
      {
        id: 't-compte', icon: '👤', heading: '2. حساب المستخدم',
        blocks: [
          { kind: 'list', items: [
            { bold: 'الدقة', text: ' — يجب عليك تقديم معلومات دقيقة وكاملة.' },
            { bold: 'الاستخدام الشخصي', text: ' — لا يمكن نقل الحساب أو مشاركته.' },
            { bold: 'السرية', text: ' — أنت مسؤول عن سرية بيانات الاعتماد الخاصة بك.' },
            { bold: 'القبول', text: ' — إنشاء حساب يعني قبول هذه الشروط وسياسة الخصوصية.' },
          ] },
        ],
      },
      {
        id: 't-kyc', icon: '🪪', heading: '3. التحقق من KYC / AML',
        blocks: [
          { kind: 'p', text: 'وفقًا للوائح المالية، يمكننا:' },
          { kind: 'list', items: [
            { bold: 'المستندات', text: ' — طلب مستندات الهوية وصورة شخصية.' },
            { bold: 'التعليق', text: ' — تعليق حساب في حالة الاشتباه في الاحتيال أو غسل الأموال.' },
            { bold: 'الرفض', text: ' — رفض بعض العمليات أو عمليات السحب دون تحقق مسبق.' },
          ] },
        ],
      },
      {
        id: 't-services', icon: '⚙️', heading: '4. الخدمات',
        blocks: [
          { kind: 'p', text: 'توفر Nexus خدمات التحويل وتحويل العملات وإدارة المحافظ. يتم تنفيذ العمليات بواسطة مزودين خارجيين؛ وقد تختلف شروطهم وتوافرهم. نتخذ إجراءات معقولة لضمان الاستمرارية، دون ضمان غير مشروط للتوافر.' },
        ],
      },
      {
        id: 't-frais', icon: '🧾', heading: '5. الرسوم والأسعار',
        blocks: [
          { kind: 'list', items: [
            { bold: 'الشفافية', text: ' — تُعرض رسوم كل عملية قبل التأكيد.' },
            { bold: 'أسعار الصرف', text: ' — أسعار المزود وقت العملية.' },
            { bold: 'عمولة Nexus', text: ' — قد تُطبق، وتُذكر دائمًا مسبقًا.' },
          ] },
        ],
      },
      {
        id: 't-responsabilite', icon: '⚖️', heading: '6. حدود المسؤولية',
        blocks: [
          { kind: 'p', text: 'لا تتحمل Nexus المسؤولية عن الانقطاعات أو الأضرار الناتجة عن المزودين أو الشبكات أو القوة القاهرة. تقتصر مسؤوليتنا على مبلغ الرسوم المدفوعة خلال الفترة ذات الصلة، ضمن الحدود المنصوص عليها قانونًا.' },
        ],
      },
      {
        id: 't-resiliation', icon: '🚪', heading: '7. الإنهاء',
        blocks: [
          { kind: 'p', text: 'يمكنك إغلاق حسابك في أي وقت عبر الإعدادات أو بالاتصال بالدعم. يمكننا تعليق أو إغلاق حساب في حالة انتهاك هذه الشروط أو لاقتضاء تنظيمي، مع إشعار في الحدود التي يسمح بها القانون.' },
        ],
      },
      {
        id: 't-droit', icon: '🏛️', heading: '8. القانون المطبق',
        blocks: [
          { kind: 'p', text: 'تخضع هذه الشروط للقانون الفرنسي. في حالة النزاع، سيبحث الطرفان أولاً عن حل ودي؛ وإلا، فستختص المحاكم المختصة وفقًا للقانون.' },
        ],
      },
    ],
  },

  docs: {
    kicker: 'المطورون · API',
    title: 'التوثيق وواجهة API',
    updated: '16 أغسطس 2026',
    summaryIcon: '💡',
    summaryTitle: 'باختصار',
    summaryText: 'توفر Nexus واجهة API REST لتنسيق المدفوعات والتحويلات متعددة المزودين. قم بالمصادقة عبر رمز JWT، ونفذ التحويلات والتحويلات النقدية، وانتقل بسهولة من بيئة الاختبار إلى الإنتاج.',
    toc: [
      { id: 'd-intro', label: 'مقدمة', icon: '🚀' },
      { id: 'd-auth', label: 'المصادقة', icon: '🔑' },
      { id: 'd-endpoints', label: 'نقاط النهاية', icon: '🔌' },
      { id: 'd-transfers', label: 'التحويلات', icon: '💸' },
      { id: 'd-convert', label: 'التحويل النقدي', icon: '🔄' },
      { id: 'd-env', label: 'البيئات', icon: '🌐' },
      { id: 'd-erreurs', label: 'الأخطاء', icon: '⚠️' },
      { id: 'd-support', label: 'الدعم', icon: '✉️' },
    ],
    sections: [
      {
        id: 'd-intro', icon: '🚀', heading: '1. مقدمة',
        blocks: [
          { kind: 'p', text: 'تعتمد واجهة Nexus على HTTP REST وتعيد استجابات JSON. جميع الطلبات تسبقها /api. عنوان URL الأساسي في التطوير هو http://localhost:8080.' },
        ],
      },
      {
        id: 'd-auth', icon: '🔑', heading: '2. المصادقة',
        blocks: [
          { kind: 'p', text: 'أنشئ حسابًا أو سجل الدخول للحصول على رمز JWT، ثم أرسله في الترويسة:' },
          { kind: 'code', text: 'Authorization: Bearer <your_token>' },
          { kind: 'p', text: 'نقاط النهاية العامة (/api/auth/*) لا تتطلب رمزًا.' },
        ],
      },
      {
        id: 'd-endpoints', icon: '🔌', heading: '3. نقاط النهاية الرئيسية',
        blocks: [
          { kind: 'table', head: ['الطريقة', 'المسار', 'الوصف'], rows: [
            ['POST', '/api/auth/register', 'إنشاء حساب'],
            ['POST', '/api/auth/login', 'تسجيل الدخول'],
            ['GET', '/api/wallets', 'عرض المحافظ'],
            ['GET', '/api/wallets/rates', 'أسعار الصرف'],
            ['POST', '/api/wallets/convert', 'تحويل العملات'],
            ['POST', '/api/transfers', 'إنشاء تحويل'],
          ] },
        ],
      },
      {
        id: 'd-transfers', icon: '💸', heading: '4. التحويلات',
        blocks: [
          { kind: 'p', text: 'لتنفيذ تحويل، أنشئ طلبًا يتضمن العملة والمبلغ والمستلم. العمليات غير قابلة للتكرار: نفس مفتاح التكرار يمنع الازدواجية.' },
          { kind: 'code', text: 'POST /api/transfers\n{ "currency": "EUR", "amount": 100, "destination": "IBAN..." }' },
        ],
      },
      {
        id: 'd-convert', icon: '🔄', heading: '5. التحويل النقدي',
        blocks: [
          { kind: 'p', text: 'يستخدم التحويل النقدي السعر الساري لدى المزود. يُحسب المبلغ المحول وقت التنفيذ، وتُخصم الرسوم من الإجمالي.' },
          { kind: 'code', text: 'POST /api/wallets/convert\n{ "from": "EUR", "to": "XAF", "amount": 250 }' },
        ],
      },
      {
        id: 'd-env', icon: '🌐', heading: '6. البيئات',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Sandbox', text: ' — الاختبار والتكامل، أموال وهمية.' },
            { bold: 'الإنتاج', text: ' — عمليات حقيقية، بيانات اعتماد منفصلة تمامًا.' },
          ] },
          { kind: 'p', text: 'مفاتيح API مشفرة ولا تُكشف أبدًا في جهة العميل. تُسجل جميع العمليات في سجل تدقيق.', className: 'note' },
        ],
      },
      {
        id: 'd-erreurs', icon: '⚠️', heading: '7. معالجة الأخطاء',
        blocks: [
          { kind: 'p', text: 'تُرجع الأخطاء مع رمز HTTP مناسب ونص JSON:' },
          { kind: 'code', text: '{ "success": false, "error": "message", "code": "CODE" }' },
          { kind: 'list', items: [
            { bold: '400', text: ' — طلب غير صالح · 401 — غير مصادق' },
            { bold: '403', text: ' — رفض الوصول · 404 — غير موجود' },
            { bold: '429', text: ' — طلبات كثيرة جدًا · 500 — خطأ داخلي' },
          ] },
        ],
      },
      {
        id: 'd-support', icon: '✉️', heading: '8. دعم المطورين',
        blocks: [
          { kind: 'support', text: 'سؤال حول واجهة API؟ افتح تذكرة عبر', linkLabel: 'مركز الدعم لدينا' },
        ],
      },
    ],
  },

  support: {
    kicker: 'المساعدة',
    title: 'مركز الدعم',
    updated: '16 أغسطس 2026',
    summaryIcon: '💡',
    summaryTitle: 'باختصار',
    summaryText: 'مساعدنا الآلي متاح على مدار الساعة في لوحة التحكم الخاصة بك. للطلبات المعقدة، يتولى وكيل بشري المهمة بسرعة. ستجد أيضًا إجابات للأسئلة الشائعة أدناه.',
    toc: [
      { id: 's-chat', label: 'الدردشة المباشرة', icon: '💬' },
      { id: 's-dispo', label: 'التوافر', icon: '🕘' },
      { id: 's-faq', label: 'الأسئلة الشائعة', icon: '❓' },
      { id: 's-contact', label: 'الاتصال', icon: '✉️' },
    ],
    sections: [
      {
        id: 's-chat', icon: '💬', heading: '1. الدردشة المباشرة',
        blocks: [
          { kind: 'p', text: 'تتوفر الدردشة عبر الزر في أسفل يمين لوحة التحكم. يجيب المساعد فورًا على الأسئلة الشائعة، ويتولى وكيل بشري المهمة عند الحاجة — ويُحتفظ بالتذكرة وسجلها.' },
        ],
      },
      {
        id: 's-dispo', icon: '🕘', heading: '2. التوافر',
        blocks: [
          { kind: 'list', items: [
            { bold: 'المساعد الآلي', text: ' — على مدار الساعة طوال أيام الأسبوع.' },
            { bold: 'الوكلاء البشريون', text: ' — من 9:00 إلى 18:00 (بتوقيت وسط أوروبا)، من الاثنين إلى الجمعة.' },
          ] },
        ],
      },
      {
        id: 's-faq', icon: '❓', heading: '3. الأسئلة الشائعة',
        blocks: [
          { kind: 'p', text: 'كيف أرسل الأموال؟ — انتقل إلى «إرسال»، واختر العملة والمستلم، ثم أكد.' },
          { kind: 'p', text: 'ما هي الرسوم؟ — تُعرض الرسوم قبل كل تأكيد لعملية.' },
          { kind: 'p', text: 'كيف أتحقق من حسابي؟ — انتقل إلى «KYC» مع مستند هوية وصورة شخصية.' },
          { kind: 'p', text: 'كيف أغير كلمة المرور؟ — في إعدادات الحساب، قسم «الأمان».' },
        ],
      },
      {
        id: 's-contact', icon: '✉️', heading: '4. الاتصال',
        blocks: [
          { kind: 'p', text: 'استخدم الدردشة المدمجة في لوحة التحكم لأي طلب. ترد فرقنا خلال 24 ساعة عمل. للاستفسارات التجارية أو الصحفية: contact@nexus-tech.io.' },
        ],
      },
    ],
  },
};

// ══════════════════════════════════════════════════════════════════════════
// 中文
// ══════════════════════════════════════════════════════════════════════════
const zh: InfoPageSet = {
  layout: {
    back: '← 返回',
    tocLabel: '目录',
    updatedLabel: '最后更新',
    printLabel: '打印',
    ariaToc: '文档目录',
  },

  privacy: {
    kicker: '法律 · 隐私',
    title: '隐私政策',
    updated: '2026年8月16日',
    summaryIcon: '💡',
    summaryTitle: '摘要',
    summaryText: '我们仅收集提供服务、验证您的身份和防止欺诈所需的数据（身份、联系方式、交易记录）。我们绝不会出售您的数据。您可以随时访问、更正或删除您的信息。',
    trust: [
      { icon: '🛡️', label: '符合GDPR' },
      { icon: '🔐', label: 'AES-256加密' },
      { icon: '🏦', label: 'KYC / AML' },
      { icon: '🌍', label: '数据位于欧盟' },
    ],
    toc: [
      { id: 'p-collecte', label: '我们收集的数据', icon: '📥' },
      { id: 'p-usage', label: '使用方式', icon: '⚙️' },
      { id: 'p-partage', label: '共享', icon: '🤝' },
      { id: 'p-cookies', label: 'Cookie与跟踪', icon: '🍪' },
      { id: 'p-droits', label: '您的权利', icon: '🛡️' },
      { id: 'p-securite', label: '安全', icon: '🔒' },
      { id: 'p-conservation', label: '保留期限', icon: '🗄️' },
      { id: 'p-contact', label: '联系方式', icon: '✉️' },
    ],
    sections: [
      {
        id: 'p-collecte', icon: '📥', heading: '1. 我们收集的数据',
        blocks: [
          { kind: 'p', text: '我们仅收集平台运营所需的信息，仅此而已：' },
          { kind: 'list', items: [
            { bold: '账户与身份', text: ' — 姓名、邮箱、电话、居住国、出生日期。' },
            { bold: '企业（专业客户）', text: ' — 法律形式、注册号、行业、规模。' },
            { bold: '交易与钱包', text: ' — 余额、操作、货币、适用汇率。' },
            { bold: '使用与技术', text: ' — IP地址、浏览器类型、访问页面（出于安全目的）。' },
            { bold: '支持', text: ' — 您通过支持聊天发送的消息和文件。' },
          ] },
          { kind: 'p', text: '我们绝不会以明文存储您的银行卡号：支付由我们的服务商处理，仅保留安全令牌。', className: 'muted' },
        ],
      },
      {
        id: 'p-usage', icon: '⚙️', heading: '2. 我们如何使用您的数据',
        blocks: [
          { kind: 'p', text: '您的数据基于明确的法律依据进行处理：' },
          { kind: 'list', items: [
            { bold: '合同履行', text: ' — 提供转账、兑换和钱包管理服务。' },
            { bold: '法律义务', text: ' — KYC验证、反洗钱（AML）、所需申报。' },
            { bold: '合法利益', text: ' — 欺诈预防、账户安全、服务改进。' },
            { bold: '同意', text: ' — 营销通知（仅在您同意时，可随时撤销）。' },
          ] },
        ],
      },
      {
        id: 'p-partage', icon: '🤝', heading: '3. 数据共享',
        blocks: [
          { kind: 'p', text: '我们绝不会出售您的个人数据。仅可能共享给：' },
          { kind: 'list', items: [
            { bold: '我们的支付服务商', text: ' — 用于执行您的操作。' },
            { bold: '主管机构', text: ' — 法律要求时（KYC、AML、法院命令）。' },
            { bold: '技术分包商', text: ' — 托管、安全、支持，受保密协议约束。' },
          ] },
        ],
      },
      {
        id: 'p-cookies', icon: '🍪', heading: '4. Cookie与跟踪',
        blocks: [
          { kind: 'p', text: '我们的网站使用Cookie和本地存储用于：' },
          { kind: 'list', items: [
            { bold: '必要Cookie', text: ' — 会话、安全、偏好（语言、主题）。' },
            { bold: '受众测量', text: ' — 匿名使用统计。' },
          ] },
          { kind: 'p', text: '您可以在浏览器设置中禁用非必要Cookie。我们的会话不依赖广告跟踪器。', className: 'muted' },
        ],
      },
      {
        id: 'p-droits', icon: '🛡️', heading: '5. 您的权利（GDPR）',
        blocks: [
          { kind: 'list', items: [
            { bold: '访问权', text: ' — 获取您的数据副本。' },
            { bold: '更正权', text: ' — 更正不准确的信息。' },
            { bold: '删除权', text: ' — 请求删除（受KYC/AML义务约束）。' },
            { bold: '限制权', text: ' — 在争议情况下限制处理。' },
            { bold: '反对权', text: ' — 反对基于合法利益的处理。' },
            { bold: '可携权', text: ' — 以可复用格式接收您的数据。' },
          ] },
          { kind: 'support', text: '如需行使这些权利，请通过以下方式提交工单：', linkLabel: '我们的支持中心' },
        ],
      },
      {
        id: 'p-securite', icon: '🔒', heading: '6. 安全',
        blocks: [
          { kind: 'list', items: [
            { bold: '密码哈希', text: ' （bcrypt），绝不存储明文。' },
            { bold: '敏感数据加密', text: ' （AES-256-GCM）静态存储。' },
            { bold: 'TLS连接', text: ' 与基于角色的访问控制（RBAC）。' },
            { bold: '审计日志', text: ' 记录所有敏感操作。' },
            { bold: '欺诈检测', text: ' 与登录尝试限制。' },
          ] },
        ],
      },
      {
        id: 'p-conservation', icon: '🗄️', heading: '7. 数据保留',
        blocks: [
          { kind: 'p', text: '在您的账户活跃期间，我们保留您的数据，之后在适用的法定期限内保留（包括KYC/AML保留义务）。这些期限结束后，您的数据将被删除或匿名化。' },
        ],
      },
      {
        id: 'p-contact', icon: '✉️', heading: '8. 联系与投诉',
        blocks: [
          { kind: 'p', text: '数据保护官（DPO）：privacy@nexus-tech.io — 客户支持：通过控制面板内聊天或 support@nexus-tech.io。如发生争议，您也可以联系主管监管机构。' },
        ],
      },
    ],
  },

  terms: {
    kicker: '法律 · 条款',
    title: '服务条款',
    updated: '2026年8月16日',
    summaryIcon: '💡',
    summaryTitle: '摘要',
    summaryText: '创建Nexus账户即表示您同意合法使用本服务并提供准确信息。费用始终在确认前显示。我们执行KYC/AML规则，并可在发生欺诈时暂停账户。',
    toc: [
      { id: 't-objet', label: '目的', icon: '📜' },
      { id: 't-compte', label: '账户', icon: '👤' },
      { id: 't-kyc', label: 'KYC / AML', icon: '🪪' },
      { id: 't-services', label: '服务', icon: '⚙️' },
      { id: 't-frais', label: '费用与汇率', icon: '🧾' },
      { id: 't-responsabilite', label: '责任', icon: '⚖️' },
      { id: 't-resiliation', label: '终止', icon: '🚪' },
      { id: 't-droit', label: '适用法律', icon: '🏛️' },
    ],
    sections: [
      {
        id: 't-objet', icon: '📜', heading: '1. 目的',
        blocks: [
          { kind: 'p', text: '本条款管辖Nexus平台的使用。Nexus是一项面向个人和企业的多币种金融编排服务（转账、兑换、钱包管理）。' },
        ],
      },
      {
        id: 't-compte', icon: '👤', heading: '2. 用户账户',
        blocks: [
          { kind: 'list', items: [
            { bold: '准确性', text: ' — 您必须提供准确、完整的信息。' },
            { bold: '个人使用', text: ' — 账户不得转让或共享。' },
            { bold: '保密性', text: ' — 您负责凭证的保密。' },
            { bold: '接受', text: ' — 创建账户即表示接受本条款和隐私政策。' },
          ] },
        ],
      },
      {
        id: 't-kyc', icon: '🪪', heading: '3. KYC / AML验证',
        blocks: [
          { kind: 'p', text: '根据金融法规，我们可能：' },
          { kind: 'list', items: [
            { bold: '文件', text: ' — 要求提供身份证明文件和自拍。' },
            { bold: '暂停', text: ' — 在涉嫌欺诈或洗钱时暂停账户。' },
            { bold: '拒绝', text: ' — 未经事先验证拒绝某些操作或提款。' },
          ] },
        ],
      },
      {
        id: 't-services', icon: '⚙️', heading: '4. 服务',
        blocks: [
          { kind: 'p', text: 'Nexus提供转账、货币兑换和钱包管理服务。操作由第三方服务商执行；其条款和可用性可能有所不同。我们采取合理措施确保连续性，但不提供无条件的可用性保证。' },
        ],
      },
      {
        id: 't-frais', icon: '🧾', heading: '5. 费用与汇率',
        blocks: [
          { kind: 'list', items: [
            { bold: '透明度', text: ' — 每笔操作的费用在确认前显示。' },
            { bold: '汇率', text: ' — 以操作时服务商的汇率为准。' },
            { bold: 'Nexus佣金', text: ' — 可能适用，始终提前说明。' },
          ] },
        ],
      },
      {
        id: 't-responsabilite', icon: '⚖️', heading: '6. 责任限制',
        blocks: [
          { kind: 'p', text: '对于因服务商、网络或不可抗力造成的中断或损害，Nexus不承担责任。我们的责任限于相关期间支付的手续费金额，在法律规定的范围内。' },
        ],
      },
      {
        id: 't-resiliation', icon: '🚪', heading: '7. 终止',
        blocks: [
          { kind: 'p', text: '您可以随时通过设置或联系支持关闭账户。如违反本条款或出于监管要求，我们可能暂停或关闭账户，并在法律允许的范围内通知。' },
        ],
      },
      {
        id: 't-droit', icon: '🏛️', heading: '8. 适用法律',
        blocks: [
          { kind: 'p', text: '本条款受法国法律管辖。发生争议时，双方将首先寻求友好解决；否则，将依法由主管法院管辖。' },
        ],
      },
    ],
  },

  docs: {
    kicker: '开发者 · API',
    title: '文档与API',
    updated: '2026年8月16日',
    summaryIcon: '💡',
    summaryTitle: '摘要',
    summaryText: 'Nexus提供REST API，用于编排多服务商支付和兑换。使用JWT令牌进行身份验证，执行转账和兑换，并轻松从测试环境切换到生产环境。',
    toc: [
      { id: 'd-intro', label: '简介', icon: '🚀' },
      { id: 'd-auth', label: '身份验证', icon: '🔑' },
      { id: 'd-endpoints', label: '端点', icon: '🔌' },
      { id: 'd-transfers', label: '转账', icon: '💸' },
      { id: 'd-convert', label: '兑换', icon: '🔄' },
      { id: 'd-env', label: '环境', icon: '🌐' },
      { id: 'd-erreurs', label: '错误', icon: '⚠️' },
      { id: 'd-support', label: '支持', icon: '✉️' },
    ],
    sections: [
      {
        id: 'd-intro', icon: '🚀', heading: '1. 简介',
        blocks: [
          { kind: 'p', text: 'Nexus API基于HTTP REST并返回JSON响应。所有请求均以/api为前缀。开发环境的基础URL为 http://localhost:8080。' },
        ],
      },
      {
        id: 'd-auth', icon: '🔑', heading: '2. 身份验证',
        blocks: [
          { kind: 'p', text: '创建账户或登录以获取JWT令牌，然后在请求头中发送：' },
          { kind: 'code', text: 'Authorization: Bearer <your_token>' },
          { kind: 'p', text: '公共端点（/api/auth/*）不需要令牌。' },
        ],
      },
      {
        id: 'd-endpoints', icon: '🔌', heading: '3. 主要端点',
        blocks: [
          { kind: 'table', head: ['方法', '路径', '描述'], rows: [
            ['POST', '/api/auth/register', '创建账户'],
            ['POST', '/api/auth/login', '登录'],
            ['GET', '/api/wallets', '列出钱包'],
            ['GET', '/api/wallets/rates', '汇率'],
            ['POST', '/api/wallets/convert', '货币兑换'],
            ['POST', '/api/transfers', '创建转账'],
          ] },
        ],
      },
      {
        id: 'd-transfers', icon: '💸', heading: '4. 转账',
        blocks: [
          { kind: 'p', text: '要执行转账，请创建包含货币、金额和收款人的请求。操作具有幂等性：相同的幂等键可防止重复。' },
          { kind: 'code', text: 'POST /api/transfers\n{ "currency": "EUR", "amount": 100, "destination": "IBAN..." }' },
        ],
      },
      {
        id: 'd-convert', icon: '🔄', heading: '5. 兑换',
        blocks: [
          { kind: 'p', text: '兑换使用服务商的当前汇率。兑换金额在执行时计算，费用从总额中扣除。' },
          { kind: 'code', text: 'POST /api/wallets/convert\n{ "from": "EUR", "to": "XAF", "amount": 250 }' },
        ],
      },
      {
        id: 'd-env', icon: '🌐', heading: '6. 环境',
        blocks: [
          { kind: 'list', items: [
            { bold: 'Sandbox', text: ' — 测试与集成，模拟资金。' },
            { bold: '生产', text: ' — 真实操作，凭证严格分离。' },
          ] },
          { kind: 'p', text: 'API密钥已加密，绝不会在客户端暴露。所有操作均记录在审计日志中。', className: 'note' },
        ],
      },
      {
        id: 'd-erreurs', icon: '⚠️', heading: '7. 错误处理',
        blocks: [
          { kind: 'p', text: '错误将以适当的HTTP代码和JSON响应体返回：' },
          { kind: 'code', text: '{ "success": false, "error": "message", "code": "CODE" }' },
          { kind: 'list', items: [
            { bold: '400', text: ' — 无效请求 · 401 — 未认证' },
            { bold: '403', text: ' — 拒绝访问 · 404 — 未找到' },
            { bold: '429', text: ' — 请求过多 · 500 — 内部错误' },
          ] },
        ],
      },
      {
        id: 'd-support', icon: '✉️', heading: '8. 开发者支持',
        blocks: [
          { kind: 'support', text: '对API有疑问？请通过以下方式提交工单：', linkLabel: '我们的支持中心' },
        ],
      },
    ],
  },

  support: {
    kicker: '帮助',
    title: '支持中心',
    updated: '2026年8月16日',
    summaryIcon: '💡',
    summaryTitle: '摘要',
    summaryText: '我们的自动助手全天候在您的控制面板中可用。对于复杂请求，人工代理会快速接管。您也可以在下方找到常见问题的答案。',
    toc: [
      { id: 's-chat', label: '在线聊天', icon: '💬' },
      { id: 's-dispo', label: '可用时间', icon: '🕘' },
      { id: 's-faq', label: '常见问题', icon: '❓' },
      { id: 's-contact', label: '联系方式', icon: '✉️' },
    ],
    sections: [
      {
        id: 's-chat', icon: '💬', heading: '1. 在线聊天',
        blocks: [
          { kind: 'p', text: '可通过控制面板右下角的按钮访问聊天。助手即时回答常见问题，需要时人工代理会接管 — 工单及其历史记录会被保留。' },
        ],
      },
      {
        id: 's-dispo', icon: '🕘', heading: '2. 可用时间',
        blocks: [
          { kind: 'list', items: [
            { bold: '自动助手', text: ' — 全天候。' },
            { bold: '人工代理', text: ' — 周一至周五，中欧时间9:00至18:00。' },
          ] },
        ],
      },
      {
        id: 's-faq', icon: '❓', heading: '3. 常见问题',
        blocks: [
          { kind: 'p', text: '如何汇款？— 进入「发送」，选择货币和收款人，然后确认。' },
          { kind: 'p', text: '费用是多少？— 每笔操作确认前都会显示费用。' },
          { kind: 'p', text: '如何验证我的账户？— 携带身份证件和自拍前往「KYC」。' },
          { kind: 'p', text: '如何更改密码？— 在账户设置的「安全」部分。' },
        ],
      },
      {
        id: 's-contact', icon: '✉️', heading: '4. 联系方式',
        blocks: [
          { kind: 'p', text: '如有任何请求，请使用控制面板内的聊天。我们的团队会在24个工作小时内回复。商务或媒体咨询：contact@nexus-tech.io。' },
        ],
      },
    ],
  },
};

export const infoPages: Record<LangCode, InfoPageSet> = { fr, en, es, pt, de, ar, zh };

/** Retourne le contenu localisé d'une page d'information (repli : fr). */
export function infoPage(page: keyof Omit<InfoPageSet, 'layout'>, lang: LangCode): InfoPageContent {
  const set = infoPages[lang] ?? infoPages.fr;
  return set[page];
}
