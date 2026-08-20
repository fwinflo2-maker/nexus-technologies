# Plan — Landing page Nexus (refonte Corridor)

**Date :** 2026-08-20  
**Spec :** `docs/superpowers/specs/2026-08-20-landing-redesign-design.md`  
**Statut :** prêt à exécuter

---

## Objectif

Remplacer la landing dark-violet (particules / torus / cards) par la landing sombre premium « Corridor » : dual CTA Personal/Business, signature réseau de corridors, i18n FR/EN, préfill `?type=` à l’inscription.

## Étapes

### 1. Fonts + tokens CSS
- Charger Syne, Manrope, IBM Plex Mono (Google Fonts ou `@fontsource` déjà dans le projet)
- Réécrire `LandingPage.css` avec tokens `--lp-*` du spec ; supprimer styles violet / glass hero / stats / arch

### 2. Composant corridors
- Créer `CorridorNetwork.tsx` (+ CSS minimal) : SVG nœuds + arcs, labels mono, animation de tracé une fois, `prefers-reduced-motion`
- Mobile : version simplifiée (moins de nœuds / opacité réduite)

### 3. Refonte `LandingPage.tsx`
- Nav (logo 3 clics, ancres, langue, Connexion)
- Hero : NEXUS + claim + lead + CTAs Personal/Business + lien Comment ça marche + `CorridorNetwork`
- Sections : steps, personal, business, confiance, CTA final, footer
- Retirer TorusField, ParticlesBackground, stats, architecture
- Navigation register : `/register?type=personal|business`

### 4. i18n
- Mettre à jour / ajouter clés landing dans `translations.ts` (FR + EN) selon le spec
- Supprimer ou laisser mortes les anciennes clés inutilisées (préférence : remplacer en place)

### 5. Prefill register
- `RegisterPage.tsx` : `useSearchParams` → `type=personal|business` au mount
- Ajuster `App.tsx` si besoin (Link/navigate avec query au lieu de `onRegister()` nu)

### 6. Vérif manuelle
- `/` desktop + mobile viewport
- CTA → register avec bon type
- Langue FR/EN
- Logo 3 clics → admin login
- Pas de violet / particules / torus

## Fichiers

| Fichier | Action |
|---------|--------|
| `LandingPage.tsx` | Rewrite structure |
| `LandingPage.css` | Rewrite tokens + layout |
| `components/CorridorNetwork.tsx` (+ css) | Create |
| `data/translations.ts` | Update landing keys |
| `views/auth/RegisterPage.tsx` | Prefill `?type=` |
| `App.tsx` | Wire register navigation with query si besoin |
| `index.html` ou CSS import fonts | Fonts |

## Hors plan

- Redesign login / dashboard
- Calculateur de frais
- Alignement tokens dashboard
