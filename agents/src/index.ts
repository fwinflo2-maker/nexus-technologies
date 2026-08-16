import express from 'express';
import cors from 'cors';
import agentsRouter from './routes/agents';

/**
 * Service d'agents NEXUS — squelette de recherche, NON exposé par défaut.
 *
 * SÉCURITÉ : POURQUOI CE SERVICE EST VERROUILLÉ
 * ─────────────────────────────────────────────
 * Ce service exposait `POST /api/intent` et `POST /api/execute` :
 *   - sans aucune authentification ;
 *   - avec `cors()` sans origine, donc ouvert à toutes ;
 *   - en écoutant sur toutes les interfaces.
 *
 * Les agents ne déplacent aucun argent (ils refusent désormais explicitement
 * d'opérer), mais un service financier joignable sans jeton reste une surface
 * d'attaque et une source de confusion. Trois verrous sont posés :
 *
 *   1. le service refuse de démarrer sans `NEXUS_AGENTS_TOKEN` ;
 *   2. toute route `/api/*` exige ce jeton en `Authorization: Bearer` ;
 *   3. l'écoute est sur 127.0.0.1 par défaut, pas sur 0.0.0.0.
 *
 * SÉPARATION DES IDENTITÉS
 * ────────────────────────
 * Ce jeton authentifie le SERVICE appelant, jamais un client Nexus. Un agent
 * n'est ni un client, ni un employé, ni un superadmin : il ne doit hériter
 * d'aucun privilège utilisateur. Les décisions financières restent la
 * prérogative de nexus-api, qui applique RBAC, environnement et ledger.
 */

const app = express();
const PORT = Number(process.env.PORT || 3001);
const HOST = process.env.HOST || '127.0.0.1';
const TOKEN = (process.env.NEXUS_AGENTS_TOKEN || '').trim();

// Fail-closed : pas de jeton, pas de service. Un défaut en dur ferait de ce
// verrou une formalité contournable.
if (TOKEN === '') {
  console.error(
    'NEXUS_AGENTS_TOKEN est absent : le service d\'agents refuse de démarrer.\n' +
      'Définir un jeton (par ex. `openssl rand -hex 32`) avant de lancer le service.',
  );
  process.exit(1);
}

// CORS restreint : liste blanche explicite, vide par défaut. Ce service est
// appelé de serveur à serveur, aucun navigateur n'a à l'atteindre.
const allowedOrigins = (process.env.NEXUS_AGENTS_ORIGINS || '')
  .split(',')
  .map((o) => o.trim())
  .filter((o) => o !== '');

app.use(cors({ origin: allowedOrigins.length > 0 ? allowedOrigins : false }));
app.use(express.json({ limit: '64kb' }));

/** Exige le jeton de service sur toute la surface /api. */
app.use('/api', (req, res, next) => {
  const header = req.get('authorization') || '';
  const provided = header.startsWith('Bearer ') ? header.slice(7).trim() : '';

  if (provided === '' || provided !== TOKEN) {
    res.status(401).json({
      success: false,
      error: 'Jeton de service requis.',
      code: 'AGENTS_UNAUTHORIZED',
      timestamp: new Date().toISOString(),
    });
    return;
  }

  next();
});

app.use('/api', agentsRouter);

// Health check : volontairement hors /api, sans secret ni détail interne.
app.get('/health', (_req, res) => {
  res.json({
    status: 'ok',
    service: 'NEXUS Agents API',
    version: '1.0.0',
    // Aucun agent n'est branché sur un moteur réel : le dire ici évite qu'un
    // superviseur interprète « ok » comme « opérationnel ».
    agents: 'not_configured',
    timestamp: new Date().toISOString(),
  });
});

app.listen(PORT, HOST, () => {
  console.log(`NEXUS Agents API — http://${HOST}:${PORT}`);
  console.log('Agents : squelettes NON branchés (aucune opération financière).');
  console.log('Conformité, routing et exécution réels : nexus-api.');
});
