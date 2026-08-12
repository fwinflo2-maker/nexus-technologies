# Hold Lifecycle API — Réservations de fonds

Documentation des endpoints de cycle de vie des **holds** (réservations de
fonds) de NEXUS. Tous les montants sont des **strings décimales** (8 décimales
max, stockage `DECIMAL(20,8)`) — jamais de flottants.

Toutes les routes sont protégées par JWT (`Authorization: Bearer <token>`).

---

## 1. Créer un hold

`POST /api/wallets/hold`

Réserve des fonds : le solde disponible du wallet est diminué, le solde gelé
(`hold_balance`) est augmenté. Aucune écriture au ledger à ce stade.

### Corps (JSON)

| Champ            | Type   | Requis | Description |
|------------------|--------|--------|-------------|
| `wallet_id`      | int    | oui    | Wallet source |
| `amount`         | string | oui    | Montant décimal (≤ 8 dp ; au-delà, tronqué à 8 dp par le stockage) |
| `currency`       | string | oui    | Devise du wallet (ex. `EUR`) |
| `idempotency_key`| string | non    | Clé d'idempotence (rejeu sans effet comptable) |
| `description`    | string | non    | Libellé libre |
| `metadata`       | object | non    | Données libres JSON |

### Réponse `200`

```json
{
  "success": true,
  "data": {
    "operation_id": "uuid",
    "status": "pending",
    "expires_at": "2026-08-11 18:30:00",
    "ttl_seconds": 1800
  }
}
```

- `status` : `pending`.
- `expires_at` : date d'expiration automatique (`created_at + HOLD_TTL_SECONDS`,
  30 min par défaut).
- `ttl_seconds` : durée de vie du hold en secondes.

### Erreurs

| Code | Erreur |
|------|--------|
| 400  | Montant invalide (≤ 0, > 8 décimales invalides, non numérique) |
| 400  | `Solde disponible insuffisant pour le hold.` |
| 400  | `Devise incohérente avec le wallet.` |
| 401  | Token manquant ou invalide |

---

## 2. Capturer un hold

`POST /api/wallets/hold/capture`

Convertit la réservation en débit permanent : écrit une entrée `debit` au
ledger (`balance_after` = solde projeté moins montant exact), décrémente
`hold_balance` et `balance`. `available_balance` reste inchangé (déjà réservé).

### Corps (JSON)

| Champ            | Type   | Requis | Description |
|------------------|--------|--------|-------------|
| `operation_id`   | string | oui    | ID de l'opération hold |
| `idempotency_key`| string | non    | Clé d'idempotence |

### Réponse `200`

```json
{
  "success": true,
  "data": { "operation_id": "uuid", "status": "completed" }
}
```

### Erreurs

| Code | Erreur |
|------|--------|
| 400  | Hold introuvable, non hold, ou non `pending` |
| 400  | `Hold balance insuffisante pour la capture.` (garde-fou projection 2 dp) |

---

## 3. Libérer un hold

`POST /api/wallets/hold/release`

Annule la réservation : `available_balance` est restauré, `hold_balance`
diminué. Aucune écriture au ledger (opération sans effet comptable net).

### Corps (JSON)

Identique à la capture (`operation_id`, `idempotency_key`).

### Réponse `200`

```json
{
  "success": true,
  "data": { "operation_id": "uuid", "status": "cancelled" }
}
```

### Erreurs

| Code | Erreur |
|------|--------|
| 400  | Hold introuvable, non hold, ou non `pending` |
| 400  | `Hold balance insuffisante pour la libération.` |

---

## 4. Lister les holds

`GET /api/wallets/holds?status=pending`

Retourne les holds de l'**utilisateur authentifié uniquement** (isolation par
`user_id` — jamais ceux d'un autre utilisateur).

### Paramètres

| Paramètre | Type | Défaut | Description |
|-----------|------|--------|-------------|
| `status`  | string | `pending` | `pending`, `completed` ou `cancelled` |

### Réponse `200`

```json
{
  "success": true,
  "data": {
    "holds": [
      {
        "operation_id": "uuid",
        "wallet_id": 42,
        "amount": "100.00000000",
        "currency": "EUR",
        "status": "pending",
        "created_at": "2026-08-11 17:30:00",
        "expires_at": "2026-08-11 18:00:00",
        "remaining_seconds": 3600
      }
    ]
  }
}
```

- `amount` : string décimale 8 dp.
- `expires_at` : `null` si le hold n'a pas d'expiration.
- `remaining_seconds` : secondes restantes avant expiration (≥ 0, `null` si
  `expires_at` est `null`). Calculé en UTC.

### Erreurs

| Code | Erreur |
|------|--------|
| 401  | Token manquant ou invalide |
| 500  | Erreur serveur (jamais de données d'un autre utilisateur) |

---

## 5. Expiration automatique (worker)

Les holds `pending` dont `expires_at <= NOW()` sont libérés automatiquement par
le worker `scripts/expire_holds.php` (boucle `scripts/expire_holds_loop.php`,
intervalle `EXPIRE_HOLD_INTERVAL_SECONDS`, 60 s par défaut).

Le worker réutilise `WalletService::releaseHold()` avec la clé d'idempotence
déterministe `expire-hold-{operation_id}` : deux workers concurrents sur le même
hold produisent **exactement un** effet comptable.

Sortie du worker :

```text
Expired: 1
Skipped: 0
Errors: 0
```

- `Expired` : holds libérés (effet comptable appliqué).
- `Skipped` : holds déjà libérés/capturés par un autre processus (aucun effet).
- `Errors` : erreurs inattendues (code de sortie 1 si > 0).
