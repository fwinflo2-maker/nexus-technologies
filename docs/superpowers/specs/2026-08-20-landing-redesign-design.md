# Design — Landing page Nexus (refonte Corridor)

**Date :** 2026-08-20  
**Statut :** approved — implémenté  
**Décisions :** approche **1 Corridor** · audience Personal + Business · sombre premium · accent bleu acier · CTAs dual dès le hero

---

## 1. Problème

La landing actuelle (`LandingPage.tsx` / `.css`) lit comme un template SaaS dark-violet :

- Particules, torus 3D, orbes glow, glassmorphism
- Stats marketing, cards features / architecture techniques
- Message générique (« argent sans frontières ») peu aligné sur la vision orchestration
- Pas de split clair Personal / Business

Elle ne porte ni la promesse Nexus ni un niveau « grande fintech » premium.

---

## 2. Objectifs

1. **Look** : finance + fintech + i-tech, sombre, premium (références : Mercury dark restraint, Plaid/Modern Treasury flow viz, ISP navy institutionnel) — sans copier une marque.
2. **Narrative** : intention → meilleure route → exécution ; split Personal / Business explicite.
3. **Conversion** : deux CTAs hero égaux → inscription pré-sélectionnée.
4. **Conserver** : i18n FR/EN, LanguageSwitcher, accès admin 3 clics sur le logo, liens footer (privacy, terms, docs, support), Connexion en nav.

### Non-objectifs

- Redesign login / register / dashboard (hors préfill `?type=`).
- Nouveau contenu marketing inventé (stats non vérifiées).
- Light mode landing.
- Remplacer le design system global de l’app — scope = landing (+ tokens/CSS locaux si besoin).

---

## 3. Structure de page

| # | Section | Job unique |
|---|---------|------------|
| 0 | Nav fixe | Logo, ancres, langue, Connexion |
| 1 | Hero | Marque + claim + 2 CTAs + signature corridors full-bleed |
| 2 | Comment ça marche | 3 étapes (intention → routes → exécution) |
| 3 | Personal | Une promesse + 1 CTA |
| 4 | Business | Une promesse + 1 CTA |
| 5 | Confiance | KYC/AML, ledger, fail-closed — langage clair |
| 6 | CTA final | Rappel dual Personal / Business |
| 7 | Footer | Liens légaux + support |

**Retiré :** particules, TorusField, orbes violet, stats strip, section architecture technique, badges pill « plateforme intelligente », cards glass génériques en hero.

---

## 4. Identité visuelle (tokens)

Synthèse recherches 2025–26 (fintech premium sombre + infrastructure tech) adaptée Nexus.

| Token | Hex | Usage |
|-------|-----|--------|
| `--lp-canvas` | `#0A0E17` | Fond page |
| `--lp-surface` | `#121826` | Bandes de section |
| `--lp-elevated` | `#1A2336` | Hover / focus surfaces |
| `--lp-ink` | `#EEF1F6` | Titres |
| `--lp-muted` | `#8B98AD` | Corps |
| `--lp-line` | `#2A3548` | Bordures / règles |
| `--lp-signal` | `#4F6EF7` | CTAs + tracés de corridors (seul accent) |
| `--lp-live` | `#3DDC97` | État positif discret uniquement |

**Typo** (choix figés)

- Display : **Syne** — hero large, tracking négatif
- Body : **Manrope** — pas Inter / Roboto / Arial
- Mono : **IBM Plex Mono** — labels corridor / data (`EUR→XAF`, frais)

**Signature visuelle**

Réseau de corridors full-bleed dans le hero : nœuds + arcs en trait fin `--lp-signal`, labels mono. Pas de photo lifestyle, pas de mock dashboard plein cadre, pas de particules.

**Motion (max 3)**

1. Fade / rise du bloc hero (marque, claim, CTAs)
2. Tracé des routes qui se dessinent une fois
3. Hover / press CTAs

Respect `prefers-reduced-motion`.

**Règles UI**

- Hero = une composition ; pas de cards dans le hero
- Radius petit (4–8px) pour blocs data ; CTAs légèrement plus arrondis (pas pills excessifs partout)
- Profondeur par couches de surface + bordures fines, pas multi-shadows ni glow violet

---

## 5. Copy (FR / EN)

### Hero

| Élément | FR | EN |
|---------|----|----|
| Marque | NEXUS | NEXUS |
| Claim | Votre intention. La meilleure route. | Your intention. The best route. |
| Lead | Nexus orchestre banques, Mobile Money et providers pour exécuter ce que vous voulez faire — au meilleur coût, délai et fiabilité. | Nexus orchestrates banks, Mobile Money and providers to execute what you intend — optimized for cost, speed and reliability. |
| CTA 1 | Personal | Personal |
| CTA 2 | Business | Business |
| Lien discret | Comment ça marche | How it works |

Pas d’eyebrow badge.

### Comment ça marche

Titre : *De l’intention à l’exécution.* / *From intention to execution.*

1. Exprimez l’intention (montant, corridor, réception)
2. Nexus compare les routes admissibles
3. Vous choisissez ; Nexus exécute et suit

### Personal

Focus : envoyer, recevoir, convertir sans gérer les providers.  
CTA → `/register?type=personal`

### Business

Focus : paiements, trésorerie opérationnelle, orchestration multi-providers.  
CTA → `/register?type=business`

### Confiance

Trois points max : vérification d’identité, sanctions / AML, soldes ancrés sur le ledger. Pas de jargon « Intent → Routing → Execution → Ledger » exposé au public.

### CTA final

Même dual Personal / Business + lien Connexion.

---

## 6. Comportement technique

### Fichiers touchés (prévus)

- `nexus-frontend/src/views/public/LandingPage.tsx` — structure
- `nexus-frontend/src/views/public/LandingPage.css` — tokens + layout (scope landing)
- `nexus-frontend/src/data/translations.ts` — clés landing FR/EN
- Composant léger corridors (inline SVG/CSS ou petit composant dédié sous `components/`) — **sans** TorusField / ParticlesBackground sur la landing
- `RegisterPage.tsx` — lire `?type=personal|business` au mount et pré-sélectionner `accountType`

### Conservé

- `handleLogoClick` admin (3 clics)
- `LanguageSwitcher`
- Props `onLogin` / `onAdminLogin` ; inscription via `navigate('/register?type=personal|business')` (ou `Link`) — ajuster `App.tsx` si `onRegister` ne passe pas la query

### Accessibilité

- Contraste ink/muted sur canvas validé
- Focus visible clavier sur CTAs / liens
- Ancres `#comment-ca-marche`, `#personal`, `#business`, `#confiance`

---

## 7. Critères de succès

- Premier viewport identifiable comme Nexus sans la nav (marque + claim + corridors)
- Visiteur comprend Personal vs Business en moins de 5 s
- Aucune trace du look violet / particules / torus
- Mobile : hero lisible, CTAs empilés, corridors simplifiés ou masqués partiellement
- i18n FR/EN à jour pour toutes les chaînes landing

---

## 8. Hors scope / suite possible

- Illustrations custom commissionnées
- Calculateur de frais interactif type Wise
- Alignement design system dashboard sur les mêmes tokens (autre chantier)
