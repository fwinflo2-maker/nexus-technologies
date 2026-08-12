# NEXUS — Hold API (Phase G+)

Réservation de fonds (hold), capture, libération manuelle et expiration automatique.

## Règles comptables

- Tous les montants sont des **décimaux à 8 chiffres** (scale 8), manipulés avec BCMath.
- Invariant toujours respecté :

  ```text
  available_balance = balance - hold_balance
  ```

- Un hold `pending` gèle un montant : `available_balance` diminue, `hold_balance` augmente.
- Une **capture** transforme le hold en débit définitif : `balance` diminue (écriture ledger), `hold_balance` diminue, `available_balance` inchangé.
- Une **libération** (manuelle ou expiration) restaure : `available_balance` augmente, `hold_balance` diminue, `balance` inchangé. Aucune écriture ledger.
- La source de vérité reste le ledger ; `wallets` n'est qu'une projection.

## Create Hold

```http
POST /wallets/hold
```

Corps :

```json
{
  "wallet_id": 1,
  "amount": "123.45000000",
  "currency": "EUR",
  "idempotency_key": "optional-uuid"
}
```

Comportement :

- Valide solde disponible, devise et format du montant.
- Réserve la clé d'idempotence, verrouille le wallet (`SELECT ... FOR UPDATE`), met à jour `hold_balance` / `available_balance`.
- Crée une opération `wallet_operations` :

  ```text
  type   = 'hold'
  status = 'pending'
  expires_at = created_at + HOLD_TTL_SECONDS
  ```

- Réponse :

  ```json
  { "operation_id": "uuid", "status": "pending" }
  ```

## Capture Hold

```http
POST /wallets/hold/capture
```

Corps :

```json
{
  "operation_id": "uuid",
  "idempotency_key": "optional-uuid"
}
```

Comportement :

- Seuls les holds `pending` appartenant à l'utilisateur peuvent être capturés.
- Délègue la comptabilisation à `LedgerService::recordHoldCapture()` (débit définitif).
- `wallet_operations.status = completed`.

Réponse :

```json
{ "operation_id": "uuid", "status": "completed" }
```

## Release Hold

```http
POST /wallets/hold/release
```

Corps :

```json
{
  "operation_id": "uuid",
  "idempotency_key": "optional-uuid"
}
```

Comportement :

- Seuls les holds `pending` appartenant à l'utilisateur peuvent être libérés.
- Restaure `available_balance` et réduit `hold_balance` (aucune écriture ledger).
- `wallet_operations.status = cancelled`.

Réponse :

```json
{ "operation_id": "uuid", "status": "cancelled" }
```

## Pending Holds

```http
GET /wallets/holds?status=pending
```

Comportement :

- Retourne les holds de l'**utilisateur authentifié uniquement** (isolation stricte).
- Filtre par `status` si fourni.

## Expiration

### Paramètres

| Paramètre | Valeur par défaut | Description |
| --- | --- | --- |
| `HOLD_TTL_SECONDS` | `1800` | Durée de vie d'un hold en secondes (30 min). |

### Principe

```text
pending hold
     ↓
expires_at atteint
     ↓
worker détecte : type='hold' AND status='pending' AND expires_at <= NOW()
     ↓
WalletService::releaseHold()
     ↓
status = cancelled
     ↓
available_balance restauré, hold_balance réduit
```

### Worker

- Scripts : `scripts/expire_holds_loop.php` (boucle persistante) et `scripts/expire_holds.php` (un cycle).
- Il **réutilise** `WalletService::releaseHold()` — il ne réimplémente aucune logique comptable.
- Clé d'idempotence déterministe :

  ```text
  expire-hold-{operation_id}
  ```

### Idempotence

Deux tentatives d'expiration sur le même `operation_id` produisent **un seul effet comptable** :

- `IdempotencyService::check()` renvoie la réponse précédente sur replay.
- Le verrouillage (`SELECT ... FOR UPDATE`) et la vérification du statut `pending` empêchent toute double libération.

### Protection concurrence

- `SELECT ... FOR UPDATE` sur `wallet_operations`.
- `SELECT ... FOR UPDATE` sur `wallets`.
- Vérification du statut `pending` avant toute écriture.
- Clé d'idempotence `expire-hold-{operation_id}`.

## Schéma — migration

```sql
ALTER TABLE wallet_operations
    ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL AFTER status;
```

Migration : `database/migrations/2026_08_12_add_expires_at_to_wallet_operations.sql`.

## Erreurs

| Condition | Comportement |
| --- | --- |
| Hold non trouvé / autre utilisateur | `RuntimeException` |
| Hold non `pending` | `RuntimeException` |
| Solde disponible insuffisant | `RuntimeException` |
| Devise incohérente | `RuntimeException` |
| Montant invalide / ≤ 0 | `RuntimeException` |
