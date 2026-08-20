# NEXUS TECHNOLOGIES — AUDIT CYCLE 3

**Date :** 2026-08-20  
**Dépôt :** `C:\Users\Florenzo\Documents\project\nexus-technologies`  
**Verdict unique :** **READY FOR INTERNAL TESTING**  
**Git :** aucun commit ; WIP primaire conservé.

## 1. Résultat exécutif

Les trois P0 de la reprise sont corrigés en code : FX à quatre jambes via
`FX_TRANSIT`, payout pawaPay v2 réellement implémenté derrière credentials, et
webhooks signés attribués par référence provider avant settlement/ledger. Les
19 erreurs Staff ont disparu, les calculs Quote/Policy utilisent BCMath, et
`users.platform_role` est désormais l'unique autorité d'autorisation employé.

Le verdict reste **READY FOR INTERNAL TESTING**, pas READY FOR SANDBOX ni
READY FOR PRODUCTION : aucune credential Stripe/pawaPay/Sumsub n'a été fournie,
aucune connexion sandbox externe ni callback signé réel n'a donc été vérifié,
et le cache FX local vide reste volontairement fail-closed.

## 2. Tests — baseline, P0, P1, final

| Moment | Commande | Résultat |
|---|---|---|
| Baseline de reprise | suite transmise par l'audit précédent | ~739 tests, ~21 erreurs, 2 failures ; erreurs surtout Staff |
| Après P0/P1 domaines | `php vendor/bin/phpunit tests/LedgerServiceTest.php tests/WalletServiceTransferTest.php tests/PawaPayAdapterTest.php tests/StripeWebhookSignatureTest.php tests/FundingWebhookAttributionTest.php tests/ExecutionSettlementTest.php tests/StaffActionsTest.php tests/StaffDashboardTest.php tests/StaffChatTest.php tests/QuotePricingTest.php tests/PolicyEngineTest.php tests/AdminEmployeesTest.php` | **92 tests, 439 assertions, 0 error, 0 failure** |
| Anti-régressions Cycle 1 | `php vendor/bin/phpunit --display-warnings tests/AuthMiddlewareInvalidationTest.php tests/AdminEmployeesTest.php tests/CredentialRotationTest.php tests/ProviderHealthServiceTest.php tests/ExecutionSettlementTest.php tests/FinancialGLTargetTest.php tests/FundingWebhookAttributionTest.php tests/CryptoTest.php tests/WalletHoldTest.php tests/ProviderCapabilityMatrixTest.php tests/RoutingOnlyImplementedProvidersTest.php` | **77 tests, 703 assertions, 0 warning/error/failure** |
| Backend final | `php vendor/bin/phpunit --display-warnings` | **750 tests, 3629 assertions, 0 warning/error/failure** |
| Frontend | `npm run build` | **PASS**, 1089 modules ; warning non bloquant chunk principal 1.66 MB |
| Frontend lint | `npm run lint` | **exit 0**, avertissements historiques React keys/hooks/Fast Refresh |

Une exécution intermédiaire avait produit 748 tests / 3617 assertions avec un
warning `pending_balance` manquant dans le SELECT verrouillé du ledger. Le SELECT
a été corrigé puis la suite finale est entièrement verte.

## 3. Ledger FX — quatre jambes et invariants

Une conversion inter-devise écrit :

1. débit `USER_POSITION.<SOURCE>` sur le wallet source ;
2. crédit `FX_TRANSIT.<SOURCE><DEST>` en devise source ;
3. débit du même compte `FX_TRANSIT` en devise destination ;
4. crédit `USER_POSITION.<DEST>` sur le wallet destination.

Chaque devise est équilibrée séparément ; les jambes transit n'ont pas de
`wallet_id`. Le débit source et le crédit destination pilotent les projections
wallet. Frais, taux, spread et provenance restent traçables dans les métadonnées
et ne sont pas transformés en écritures fictives. L'idempotence reste portée
par l'opération, les écritures sont transactionnelles et rollbackent ensemble.
Les tests couvrent EUR→XAF, même utilisateur, wallet projection, idempotence,
concurrence et vérification `debits = credits`. Une quote expirée ou un taux
absent n'atteint pas le ledger.

La sémantique hold approuvée est restaurée : create/release sans ledger ;
capture = unique débit définitif du wallet, équilibré par un crédit
`OUTBOUND_TRANSIT`. Le règlement débite ensuite ce transit GL vers
`PROVIDER_SETTLEMENT` et `NEXUS_REVENUE` sans redébiter le wallet ; un échec
provider écrit l'annulation équilibrée et recrédite le wallet.

## 4. Providers

| Provider | Adapter | Payout | Webhook | Signature | Credentials | Sandbox | Status |
|---|---|---|---|---|---|---|---|
| pawaPay | dédié Merchant API v2 | **IMPLEMENTED** (`POST /v2/payouts`, polling/idempotence/status) | câblé vers transaction/settlement | RFC 9421 + Content-Digest + clé publique | absentes | non vérifiée | code ready, `CREDENTIALS_NOT_CONFIGURED`/`NOT_VERIFIED` |
| Stripe | dédié | NOT_IMPLEMENTED | câblé pour opérations connues | `Stripe-Signature` natif : `t`, plusieurs `v1`, tolérance, `hash_equals` | `whsec` absent | non vérifiée | webhook `CONFIG_REQUIRED` |
| Sumsub | KYC dédié | N/A | IMPLEMENTED sur rail KYC | digest HMAC provider | absentes | non vérifiée | code ready, NOT_VERIFIED |
| Western Union | dédié partiel | NOT_IMPLEMENTED | générique/config requis | non vérifiée | absentes | non vérifiée | fail-closed |
| Autres providers catalogue | config-driven/catalogue | NOT_IMPLEMENTED | config requis | non vérifiée | absentes | non vérifiée | catalogue seulement |

La matrice de capacité est la source de vérité : le routage payout ne retient
que `IMPLEMENTED` **et** une configuration valide. « Adapter présent » ne vaut
jamais « provider connecté ».

## 5. Webhooks et funding

- **Stripe :** vérification du corps brut avec schéma officiel
  `timestamp.payload`, tolérance temporelle, rotation via plusieurs signatures
  `v1`, comparaison constante. Sans secret réel : `WEBHOOK_NOT_CONFIGURED`.
- **pawaPay :** Content-Digest et HTTP Message Signature RFC 9421 vérifiés avant
  JSON ; `created`/`expires` contrôlés ; clé résolue par `keyid` depuis les clés
  publiques. La compatibilité réelle reste non vérifiée sans compte sandbox et
  callback signé.
- **Attribution :** une référence provider résout une transaction ou un
  `funding_intent` pré-créé. Le `user_id` du payload n'est jamais une autorité.
- **Idempotence :** unicité `(provider, environment, event_id)` puis settlement
  idempotent.
- **Fraude :** le test dédié forge un `user_id` attaquant ; seul le wallet
  propriétaire de l'intent est crédité.
- **Settlement :** contrôle montant/devise/référence avant transition, puis
  ledger ; les événements inconnus sont enregistrés sans inventer de succès.

## 6. Employees / RBAC

Le modèle retenu est exclusivement `users.platform_role`, rechargé en base à
chaque requête. `employees.permissions` reste une colonne legacy pour
compatibilité de migration, mais le backend ne la lit plus, ne l'écrit plus et
la liste API expose `authorization_model: platform_role`. Le frontend n'envoie
plus de pseudo-permissions.

Création, invitation à durée limitée, activation/désactivation, changement de
rôle, refus de promotion silencieuse d'un client, isolation client/personnel,
dashboards/actions Staff et audit backend sont testés. Les routes de messagerie
interne Staff sont maintenant réellement enregistrées.

## 7. Sécurité revalidée

- JWT : logout par `jti`, suspension/clôture immédiate et
  `password_changed_at` invalident les anciens jetons.
- Comptes internes : restrictions et réactivation contrôlées côté serveur.
- Credentials providers : staging, activation, rotation, révocation/archive et
  isolation d'environnement ; un upsert remet `last_tested_at` à NULL, donc pas
  de faux CONNECTED.
- Webhooks : signature avant parsing, pas de payload/secret dans l'audit.
- Funding : attribution par intent, pas par identité déclarée.
- Production : absence FX/sanctions/credentials reste fail-closed.
- Aucun secret réel ni faux succès provider n'a été injecté.

## 8. Base de données

`php scripts/setup_test_db.php` a reconstruit **nexus_test** depuis le manifeste :
36 tables, cache FX vide, et a confirmé `nexus` intacte (`users=8`,
`wallets=11` au moment du contrôle).

`database/full_schema.sql` a été régénéré. Le générateur accepte désormais
correctement un mot de passe vide et retire la directive MariaDB non portable
`sandbox mode`. Résultat de `scripts/compare_schemas.sh` :

- migrations : PASS ;
- full schema : PASS ;
- 36 tables, 433 colonnes, 131 index, 47 FK, 60 ENUM ;
- **SCHEMA EQUIVALENCE PASS**.

## 9. Frontend

L'écran Send distingue maintenant explicitement `processing`/`pending`,
`completed`, `failed` et `cancelled`, puis repolle le détail serveur tant que
l'opération est asynchrone. Seul `completed` utilise l'écran vert affirmant que
le règlement et le ledger sont terminés. Les erreurs quote/no-route/FX
indisponible et l'expiration restent des états non-success.

Les clients API employés, Staff dashboard/action et chat ont été câblés ; le
build TypeScript/Vite est vert. Dette P2 : découper le bundle principal.

## 10. P0/P1 corrigés et dette restante

### Corrigé

- P0 : FX_TRANSIT quatre jambes et invariants.
- P0 : payout pawaPay v2 + polling + statuts + références.
- P0 : webhook signé → attribution → idempotence → settlement → ledger.
- P1 : Staff dashboard/action/chat réellement routés.
- P1 : Quote/Policy en BCMath avec tests de frontière décimale.
- P1 : autorisation employé unifiée sur `platform_role`.
- P1 : warning ledger funding supprimé.
- P1 : schéma complet reproductible.

### Restant

- **P0 production :** obtenir/configurer les credentials et exécuter les
  parcours sandbox réels pawaPay/Stripe/Sumsub, notamment callbacks signés,
  erreurs, timeout, retry et réconciliation provider.
- **P0 production :** charger une source FX et une source sanctions approuvées ;
  leur absence bloque volontairement.
- **P1 :** test de charge/concurrence avec infrastructure réelle et procédure
  opérationnelle de rotation des clés publiques pawaPay.
- **P2 :** supprimer physiquement `employees.permissions` dans une migration
  de rupture quand tous les consommateurs externes sont migrés.
- **P2 :** corriger les avertissements lint React historiques et code-splitter
  le bundle.
- **P3 :** observabilité/correlation IDs et finition i18n.

## 11. Niveau de preuve

- **Code ready :** oui pour les corrections listées.
- **Provider connected :** non, aucune credential réelle.
- **Sandbox verified :** non, absence d'accès externe authentifié.
- **Production verified :** non.

En conséquence : **READY FOR INTERNAL TESTING** uniquement.

---

Rapport produit après exécution réelle des tests et des installations isolées.
Aucun commit effectué. Les fichiers dangereux
`nexus-api/reset_superadmin.php`, `test_hash.php`, `encrypt_credentials.php` et
`AdminLoginPage.new.tsx` n'ont pas été modifiés ni stagés.
