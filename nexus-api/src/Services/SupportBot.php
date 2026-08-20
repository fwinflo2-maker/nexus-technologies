<?php

declare(strict_types=1);

namespace Nexus\Services;

/**
 * Conversational support bot: scored intents, follow-ups, account context.
 * No first-match keyword table — replies stay specific and never invent balances.
 */
final class SupportBot
{
    /** @param list<array{sender?:string,body?:string}> $history */
    public static function reply(string $message, array $history = [], array $ctx = []): array
    {
        $message = trim($message);
        $history = self::cleanHistory($history);
        $norm = self::normalize($message);

        if ($norm === '') {
            return self::pack('unknown', $message, $ctx, false);
        }

        if (self::isHumanRequest($norm)) {
            return self::pack('human', $message, $ctx, true);
        }

        if (self::isSecurityEscalation($norm)) {
            return self::pack('security', $message, $ctx, true);
        }

        $follow = self::followUpIntent($norm, $history);
        if ($follow !== null) {
            return self::pack($follow, $message, $ctx, false);
        }

        $scores = self::scoreIntents($norm);
        $winner = self::bestIntent($scores);
        if ($winner !== null) {
            return self::pack($winner, $message, $ctx, false);
        }

        if (self::isGreeting($norm)) {
            return self::pack('greeting', $message, $ctx, false);
        }

        if (self::isThanks($norm)) {
            return self::pack('thanks', $message, $ctx, false);
        }

        $escalate = self::lastBotWasClarification($history);

        return self::pack('unknown', $message, $ctx, $escalate);
    }

    public static function loadContext(\PDO $pdo, array $user): array
    {
        $fullName = trim((string) ($user['full_name'] ?? $user['name'] ?? ''));
        $first = $fullName === '' ? '' : (preg_split('/\s+/u', $fullName, 2)[0] ?? '');

        $ctx = [
            'first_name'     => (string) ($user['first_name'] ?? $first),
            'account_type'   => $user['account_type'] ?? null,
            'kyc_level'      => $user['kyc_level'] ?? null,
            'kyb_status'     => $user['kyb_status'] ?? null,
            'platform_role'  => $user['platform_role'] ?? null,
            'status'         => $user['status'] ?? ($user['account_status'] ?? null),
            'user_id'        => (int) ($user['id'] ?? 0),
            'wallets'        => [],
            'transactions'   => [],
            'kyc'            => null,
        ];

        $uid = (int) ($user['id'] ?? 0);
        if ($uid <= 0) {
            return $ctx;
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT currency, available_balance, pending_balance, in_transit_balance, balance
                 FROM wallets WHERE user_id = ?'
            );
            $stmt->execute([$uid]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $ctx['wallets'] = is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            $ctx['wallets'] = [];
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT type, status, amount, currency, label, destination, created_at
                 FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 3'
            );
            $stmt->execute([$uid]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $ctx['transactions'] = is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            $ctx['transactions'] = [];
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT status, reason, subject_type
                 FROM kyc_verifications WHERE user_id = ? ORDER BY updated_at DESC LIMIT 1'
            );
            $stmt->execute([$uid]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $ctx['kyc'] = is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            $ctx['kyc'] = null;
        }

        return $ctx;
    }

    // ─── Normalisation / matching ─────────────────────────────────────────

    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(["'", "’", "‘", "`", "´"], ' ', $text);
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($converted) && $converted !== '') {
            $text = strtolower($converted);
        } else {
            $text = strtr($text, [
                'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
                'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
                'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
                'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
                'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
                'ç' => 'c', 'ñ' => 'n', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
            ]);
        }
        $text = preg_replace('/[^a-z0-9\s]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    /** Word-ish match. Multi-word = substring; single token = word boundary. Never match tokens < 3 chars. */
    public static function termMatch(string $norm, string $term): bool
    {
        $term = self::normalize($term);
        if ($term === '') {
            return false;
        }
        if (str_contains($term, ' ')) {
            return str_contains($norm, $term);
        }
        if (strlen($term) < 3) {
            return false;
        }

        return (bool) preg_match('/\b' . preg_quote($term, '/') . '\b/', $norm);
    }

    private static function anyTerm(string $norm, array $terms): bool
    {
        foreach ($terms as $t) {
            if (self::termMatch($norm, $t)) {
                return true;
            }
        }

        return false;
    }

    // ─── Human / security ─────────────────────────────────────────────────

    private static function isHumanRequest(string $norm): bool
    {
        $phrases = [
            'vraie personne', 'vrai humain', 'parler a un', 'parler a une',
            'parler avec un', 'parler avec une', 'real person', 'speak to someone',
            'speak to an agent', 'talk to a human', 'talk to someone', 'talk to an agent',
            'speak with someone', 'human agent', 'assistance humaine',
        ];
        if (self::anyTerm($norm, $phrases)) {
            return true;
        }
        $words = [
            'agent', 'agents', 'humain', 'humaine', 'conseiller', 'conseillere',
            'operateur', 'operatrice', 'operator', 'manager',
            'reclamation', 'plainte', 'complaint', 'complaints',
        ];

        return self::anyTerm($norm, $words);
    }

    private static function isSecurityEscalation(string $norm): bool
    {
        $phrases = [
            'geler ma carte', 'gel ma carte', 'geler carte', 'geler le compte',
            'freeze my card', 'freeze the card', 'unauthorized', 'acces non autorise',
            'compte pirate', 'carte volee', 'carte vole',
        ];
        if (self::anyTerm($norm, $phrases)) {
            return true;
        }
        $words = [
            'fraude', 'fraud', 'pirate', 'piratage', 'pirated',
            'vole', 'volee', 'voles', 'stolen',
        ];
        if (self::anyTerm($norm, $words)) {
            return true;
        }
        $blocked = self::anyTerm($norm, ['compte bloque', 'account blocked', 'compte suspendu'])
            || (self::anyTerm($norm, ['compte', 'account']) && self::anyTerm($norm, ['bloque', 'blocked', 'suspendu']));
        $urgent = self::anyTerm($norm, ['urgence', 'urgent', 'immediatement', 'asap']);

        return $blocked && $urgent;
    }

    // ─── Scoring ──────────────────────────────────────────────────────────

    /**
     * @return array<string, array{score:int, strong:bool, spec:int}>
     */
    private static function scoreIntents(string $norm): array
    {
        $defs = self::intentDefs();
        $scores = [];
        foreach ($defs as $intent => $def) {
            $score = 0;
            $strong = false;
            foreach ($def['groups'] as $group) {
                $w = (int) $group['weight'];
                foreach ($group['terms'] as $term) {
                    if (self::termMatch($norm, $term)) {
                        $score += $w;
                        if ($w >= 3) {
                            $strong = true;
                        }
                        break;
                    }
                }
            }
            $scores[$intent] = [
                'score'  => $score,
                'strong' => $strong,
                'spec'   => (int) $def['spec'],
            ];
        }

        $hasTransfer = self::anyTerm($norm, ['transfert', 'transfer', 'envoi', 'envoyer', 'virement']);
        $hasStuck = self::anyTerm($norm, ['bloque', 'stuck', 'echoue', 'echec', 'failed', 'en panne']);
        if ($hasTransfer && $hasStuck) {
            $scores['transfer_stuck']['score'] += 4;
            $scores['transfer_stuck']['strong'] = true;
        }
        $hasDelay = self::anyTerm($norm, ['delai', 'delais', 'combien de temps', 'how long', 'duration']);
        if ($hasTransfer && $hasDelay) {
            $scores['transfer_delay']['score'] += 3;
            $scores['transfer_delay']['strong'] = true;
        }
        $hasDev = self::anyTerm($norm, ['devise', 'devises', 'currency', 'currencies']);
        if ($hasTransfer && $hasDev) {
            $scores['transfer_currencies']['score'] += 3;
            $scores['transfer_currencies']['strong'] = true;
        }
        $hasFees = self::anyTerm($norm, ['frais', 'fee', 'fees', 'commission']);
        $hasIntl = self::anyTerm($norm, ['international', 'etranger', 'abroad', 'fx', 'change']);
        if ($hasFees && $hasIntl) {
            $scores['fees_intl']['score'] += 3;
            $scores['fees_intl']['strong'] = true;
        }
        $hasKyc = self::anyTerm($norm, ['kyc', 'verification', 'identite', 'identity', 'dossier']);
        if ($hasKyc && self::anyTerm($norm, ['refuse', 'refused', 'rejected', 'recale'])) {
            $scores['kyc_rejected']['score'] += 4;
            $scores['kyc_rejected']['strong'] = true;
        }
        if ($hasKyc && self::anyTerm($norm, ['en attente', 'pending', 'en cours', 'in progress'])) {
            $scores['kyc_pending']['score'] += 3;
            $scores['kyc_pending']['strong'] = true;
        }
        if (self::anyTerm($norm, ['compte', 'account']) && self::anyTerm($norm, ['bloque', 'blocked', 'suspendu', 'suspended'])) {
            $scores['account_blocked']['score'] += 4;
            $scores['account_blocked']['strong'] = true;
        }

        return $scores;
    }

    /** @return array<string, array{spec:int, groups:list<array{weight:int, terms:list<string>}>}> */
    private static function intentDefs(): array
    {
        return [
            'transfer_stuck' => [
                'spec' => 40,
                'groups' => [
                    ['weight' => 5, 'terms' => ['transfert bloque', 'envoi bloque', 'virement bloque', 'transfer stuck', 'transfer blocked']],
                    ['weight' => 2, 'terms' => ['stuck', 'echoue', 'echec', 'failed', 'en panne']],
                    ['weight' => 1, 'terms' => ['bloque', 'blocked']],
                ],
            ],
            'transfer_delay' => [
                'spec' => 39,
                'groups' => [
                    ['weight' => 3, 'terms' => ['delai', 'delais', 'combien de temps', 'how long', 'processing time', 'duree']],
                    ['weight' => 2, 'terms' => ['lent', 'lente', 'slow', 'toujours pas recu', 'pas encore recu']],
                ],
            ],
            'transfer_currencies' => [
                'spec' => 38,
                'groups' => [
                    ['weight' => 4, 'terms' => ['devises', 'devise', 'currencies', 'quelles devises', 'which currencies']],
                    ['weight' => 2, 'terms' => ['usdt', 'usdc', 'mobile money', 'cash pickup', 'rails']],
                    ['weight' => 1, 'terms' => ['international']],
                ],
            ],
            'transfer' => [
                'spec' => 20,
                'groups' => [
                    ['weight' => 3, 'terms' => ['envoyer', 'envoi', 'envois', 'transfert', 'transfer', 'virement', 'send money', 'envoyer de l argent']],
                    ['weight' => 2, 'terms' => ['destinataire', 'faire un transfert', 'envoyer de l']],
                    ['weight' => 1, 'terms' => ['argent', 'payer']],
                ],
            ],
            'balance_gap' => [
                'spec' => 30,
                'groups' => [
                    ['weight' => 3, 'terms' => ['ecart', 'en attente', 'pending', 'in transit', 'en transit', 'disponible vs', 'gap']],
                    ['weight' => 2, 'terms' => ['pourquoi mon solde', 'solde different', 'manque de l argent']],
                ],
            ],
            'balance' => [
                'spec' => 20,
                'groups' => [
                    ['weight' => 3, 'terms' => ['solde', 'balance', 'portefeuille', 'wallet', 'wallets', 'combien j ai']],
                    ['weight' => 2, 'terms' => ['disponible', 'mes fonds', 'my funds']],
                ],
            ],
            'account_blocked' => [
                'spec' => 25,
                'groups' => [
                    ['weight' => 5, 'terms' => ['compte bloque', 'account blocked', 'compte suspendu', 'account suspended']],
                    ['weight' => 3, 'terms' => ['suspendu', 'suspended', 'desactive', 'disabled']],
                ],
            ],
            'kyc_rejected' => [
                'spec' => 40,
                'groups' => [
                    ['weight' => 4, 'terms' => ['kyc refuse', 'dossier refuse', 'verification refusee', 'kyc rejected', 'file rejected']],
                    ['weight' => 2, 'terms' => ['recale', 'rejet', 'rejected']],
                ],
            ],
            'kyc_pending' => [
                'spec' => 39,
                'groups' => [
                    ['weight' => 4, 'terms' => ['kyc en attente', 'verification en attente', 'dossier en cours', 'kyc pending']],
                    ['weight' => 2, 'terms' => ['en attente', 'pending', 'en cours', 'toujours pas valide']],
                ],
            ],
            'kyc_docs' => [
                'spec' => 38,
                'groups' => [
                    ['weight' => 4, 'terms' => ['quels documents', 'which documents', 'documents acceptes', 'passeport', 'titre de sejour']],
                    ['weight' => 3, 'terms' => ['selfie', 'cni', 'piece d identite']],
                    ['weight' => 2, 'terms' => ['documents', 'justificatif']],
                ],
            ],
            'kyc' => [
                'spec' => 20,
                'groups' => [
                    ['weight' => 4, 'terms' => ['kyc']],
                    ['weight' => 3, 'terms' => ['verification', 'identite', 'identity']],
                    ['weight' => 2, 'terms' => ['verifier mon compte', 'verify my account']],
                ],
            ],
            'fees_why' => [
                'spec' => 40,
                'groups' => [
                    ['weight' => 4, 'terms' => ['pourquoi des frais', 'why fees', 'frais caches', 'hidden fee', 'hidden fees', 'pourquoi les frais', 'why are fees']],
                    ['weight' => 3, 'terms' => ['frais caches', 'hidden fee']],
                ],
            ],
            'fees_intl' => [
                'spec' => 39,
                'groups' => [
                    ['weight' => 3, 'terms' => ['frais international', 'international fees', 'frais a l etranger']],
                    ['weight' => 2, 'terms' => ['spread', 'fx']],
                    ['weight' => 1, 'terms' => ['international', 'etranger']],
                ],
            ],
            'fees' => [
                'spec' => 20,
                'groups' => [
                    ['weight' => 3, 'terms' => ['frais', 'commission', 'commissions', 'tarif', 'tarifs', 'fee', 'fees', 'cout', 'couts']],
                    ['weight' => 2, 'terms' => ['facturation', 'billing']],
                ],
            ],
            'password' => [
                'spec' => 22,
                'groups' => [
                    ['weight' => 4, 'terms' => ['mot de passe', 'password', 'mdp', 'mot de passe oublie', 'forgot password']],
                    ['weight' => 3, 'terms' => ['connexion', 'login', 'se connecter', 'sign in', 'signin']],
                    ['weight' => 2, 'terms' => ['identifiants', 'credentials']],
                ],
            ],
            'beneficiaries' => [
                'spec' => 22,
                'groups' => [
                    ['weight' => 4, 'terms' => ['destinataires', 'beneficiaires', 'beneficiaries', 'beneficiary']],
                    ['weight' => 3, 'terms' => ['ajouter un destinataire', 'carnet d adresses']],
                ],
            ],
            'limits' => [
                'spec' => 22,
                'groups' => [
                    ['weight' => 4, 'terms' => ['plafond', 'plafonds', 'limite', 'limites', 'limits', 'limit']],
                    ['weight' => 2, 'terms' => ['montant max', 'maximum']],
                ],
            ],
            'transactions' => [
                'spec' => 21,
                'groups' => [
                    ['weight' => 4, 'terms' => ['historique', 'transactions', 'mes operations', 'transaction history']],
                    ['weight' => 2, 'terms' => ['operations', 'releves']],
                ],
            ],
            'business' => [
                'spec' => 22,
                'groups' => [
                    ['weight' => 4, 'terms' => ['business', 'entreprise', 'kyb', 'compte business']],
                    ['weight' => 3, 'terms' => ['equipe', 'team', 'collaborateurs']],
                ],
            ],
            'connect' => [
                'spec' => 23,
                'groups' => [
                    ['weight' => 4, 'terms' => ['nexus connect', 'connect api', 'api']],
                    ['weight' => 3, 'terms' => ['webhooks', 'webhook', 'employes', 'employees']],
                ],
            ],
            'hours' => [
                'spec' => 22,
                'groups' => [
                    ['weight' => 4, 'terms' => ['horaires', 'heures d ouverture', 'opening hours', 'when are you open']],
                    ['weight' => 3, 'terms' => ['horaire', 'ouvert', '24 7', '24/7']],
                    ['weight' => 2, 'terms' => ['disponibles', 'available now']],
                ],
            ],
        ];
    }

    /**
     * @param array<string, array{score:int, strong:bool, spec:int}> $scores
     */
    private static function bestIntent(array $scores): ?string
    {
        $best = null;
        $bestScore = -1;
        $bestSpec = -1;
        foreach ($scores as $intent => $info) {
            $s = $info['score'];
            $ok = $s >= 2 || ($info['strong'] && $s >= 1);
            if (!$ok) {
                continue;
            }
            if ($s > $bestScore || ($s === $bestScore && $info['spec'] > $bestSpec)) {
                $bestScore = $s;
                $bestSpec = $info['spec'];
                $best = $intent;
            }
        }

        return $best;
    }

    private static function followUpIntent(string $norm, array $history): ?string
    {
        if (!self::lastFamilyIs($history, 'transfer')) {
            return null;
        }
        if (self::anyTerm($norm, ['bloque', 'blocked', 'stuck', 'echoue', 'echec', 'failed', 'en panne'])) {
            $aboutAccount = self::anyTerm($norm, ['compte', 'account'])
                && !self::anyTerm($norm, ['transfert', 'transfer', 'envoi', 'virement']);
            if ($aboutAccount) {
                return null;
            }
            return 'transfer_stuck';
        }
        if (self::anyTerm($norm, ['delai', 'delais', 'combien de temps', 'how long', 'lent', 'slow', 'duree'])) {
            return 'transfer_delay';
        }
        if (self::anyTerm($norm, ['devise', 'devises', 'currency', 'currencies', 'international', 'usdt', 'usdc'])) {
            return 'transfer_currencies';
        }

        return null;
    }

    private static function lastFamilyIs(array $history, string $family): bool
    {
        $n = count($history);
        $from = max(0, $n - 6);
        for ($i = $n - 1; $i >= $from; $i--) {
            $body = self::normalize((string) ($history[$i]['body'] ?? ''));
            if ($body === '') {
                continue;
            }
            if ($family === 'transfer' && self::anyTerm($body, [
                'transfert', 'transfer', 'envoyer', 'envoi', 'virement', 'destinataire',
                'devise', 'delai', 'send money', 'mobile money',
            ])) {
                return true;
            }
        }

        return false;
    }

    private static function isGreeting(string $norm): bool
    {
        return self::anyTerm($norm, [
            'bonjour', 'bonsoir', 'salut', 'hello', 'hey', 'hi', 'coucou',
            'aide', 'help', 'menu', 'assalam', 'hola',
        ]);
    }

    private static function isThanks(string $norm): bool
    {
        if (self::anyTerm($norm, [
            'merci', 'thanks', 'thank you', 'merci beaucoup', 'ok merci',
            'super', 'parfait', 'compris', 'd accord', 'nickel',
        ])) {
            return true;
        }

        return in_array($norm, ['ok', 'okay', 'top', 'cool'], true);
    }

    private static function lastBotWasClarification(array $history): bool
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $sender = strtolower((string) ($history[$i]['sender'] ?? ''));
            if ($sender !== 'bot') {
                continue;
            }
            $n = self::normalize((string) ($history[$i]['body'] ?? ''));
            if (str_contains($n, 'pas sur de comprendre')
                || str_contains($n, 'je n ai pas bien compris')
                || str_contains($n, 'not sure i understand')
                || str_contains($n, 'didn t quite catch')
                || str_contains($n, 'did not quite catch')
                || (str_contains($n, 'preciser') && (str_contains($n, 'comprend') || str_contains($n, 'sujet')))
                || str_contains($n, 'could you clarify')
            ) {
                return true;
            }

            return false;
        }

        return false;
    }

    // ─── Pack / language ──────────────────────────────────────────────────

    private static function pack(string $intent, string $message, array $ctx, bool $escalate): array
    {
        $fr = self::isFr($ctx);
        [$reply, $category, $subject, $quick] = self::compose($intent, $message, $ctx, $escalate, $fr);

        if ($escalate && ($reply === null || $reply === '')) {
            $reply = $fr
                ? 'Je vous connecte à un conseiller.'
                : 'I am connecting you with an advisor.';
        }

        return [
            'reply'         => $reply,
            'escalate'      => $escalate,
            'category'      => $category,
            'subject'       => $subject !== '' ? $subject : mb_substr($message, 0, 120),
            'intent'        => $intent,
            'quick_replies' => $escalate ? [] : $quick,
        ];
    }

    private static function isFr(array $ctx): bool
    {
        $lang = strtolower(trim((string) ($ctx['lang'] ?? 'fr')));
        if ($lang === '') {
            return true;
        }

        return str_starts_with($lang, 'fr');
    }

    private static function firstName(array $ctx): string
    {
        return trim((string) ($ctx['first_name'] ?? ''));
    }

    private static function hello(array $ctx, bool $fr): string
    {
        $n = self::firstName($ctx);
        if ($n !== '') {
            return $fr ? 'Bonjour ' . $n : 'Hello ' . $n;
        }

        return $fr ? 'Bonjour' : 'Hello';
    }

    private static function agentChip(bool $fr): string
    {
        return $fr ? 'Parler à un agent' : 'Speak to an agent';
    }

    private static function money(float|int|string|null $amount): string
    {
        return number_format((float) $amount, 2, ',', ' ');
    }

    /**
     * @return array{0:?string,1:string,2:string,3:list<string>}
     */
    private static function compose(string $intent, string $message, array $ctx, bool $escalate, bool $fr): array
    {
        $agent = self::agentChip($fr);
        $hello = self::hello($ctx, $fr);

        return match ($intent) {
            'human' => [
                $fr ? 'Je vous connecte à un conseiller.' : 'I am connecting you with an advisor.',
                'other',
                $fr ? 'Demande de conseiller' : 'Request for an advisor',
                [],
            ],
            'security' => [
                $fr
                    ? 'Je prends votre signalement au sérieux. Je vous connecte immédiatement à un conseiller.'
                    : 'I am taking this report seriously. Connecting you with an advisor right away.',
                'account',
                mb_substr($message, 0, 120),
                [],
            ],
            'transfer' => self::replyTransfer($ctx, $fr, $hello, $agent),
            'transfer_stuck' => self::replyTransferStuck($ctx, $fr, $agent),
            'transfer_delay' => self::replyTransferDelay($fr, $agent),
            'transfer_currencies' => self::replyTransferCurrencies($fr, $agent),
            'balance' => self::replyBalance($ctx, $fr, $hello, $agent),
            'balance_gap' => self::replyBalanceGap($ctx, $fr, $agent),
            'account_blocked' => self::replyAccountBlocked($ctx, $fr, $agent),
            'kyc' => self::replyKyc($ctx, $fr, $agent),
            'kyc_docs' => self::replyKycDocs($fr, $agent),
            'kyc_pending' => self::replyKycPending($ctx, $fr, $agent),
            'kyc_rejected' => self::replyKycRejected($ctx, $fr, $agent),
            'fees' => self::replyFees($fr, $agent),
            'fees_intl' => self::replyFeesIntl($fr, $agent),
            'fees_why' => self::replyFeesWhy($fr, $agent),
            'password' => self::replyPassword($fr, $agent),
            'beneficiaries' => self::replyBeneficiaries($fr, $agent),
            'limits' => self::replyLimits($ctx, $fr, $agent),
            'transactions' => self::replyTransactions($ctx, $fr, $agent),
            'business' => self::replyBusiness($ctx, $fr, $agent),
            'connect' => self::replyConnect($fr, $agent),
            'hours' => self::replyHours($fr, $agent),
            'greeting', 'menu' => self::replyGreeting($ctx, $fr, $hello, $agent),
            'thanks' => self::replyThanks($fr, $agent),
            default => self::replyUnknown($escalate, $fr, $agent, $message),
        };
    }

    // ─── Intent replies ───────────────────────────────────────────────────

    private static function replyTransfer(array $ctx, bool $fr, string $hello, string $agent): array
    {
        $tx = self::lastTxLine($ctx, $fr);
        if ($fr) {
            $reply = $hello . '. Pour envoyer de l’argent : 1) ouvrez **Envoyer**, 2) choisissez la devise, 3) sélectionnez le destinataire, 4) vérifiez le montant et confirmez.';
            if ($tx !== null) {
                $reply .= ' Votre dernière opération : ' . $tx . '.';
            }
        } else {
            $reply = $hello . '. To send money: 1) open **Send**, 2) choose the currency, 3) pick the recipient, 4) review the amount and confirm.';
            if ($tx !== null) {
                $reply .= ' Your latest transfer: ' . $tx . '.';
            }
        }

        return [
            $reply,
            'transfer',
            $fr ? 'Question sur un transfert' : 'Question about a transfer',
            $fr
                ? ['Mon transfert est bloqué', 'Quels sont les délais ?', 'Quelles devises sont supportées ?', $agent]
                : ['My transfer is stuck', 'What are the timelines?', 'Which currencies are supported?', $agent],
        ];
    }

    private static function replyTransferStuck(array $ctx, bool $fr, string $agent): array
    {
        $stuck = self::stuckTx($ctx);
        if ($stuck !== null) {
            $line = self::formatTx($stuck, $fr);
            $reply = $fr
                ? 'Je vois une opération encore en cours ou en échec : ' . $line . '. Vérifiez **Historique** ; si le statut ne change pas, un conseiller peut investiguer.'
                : 'I can see a transfer still processing or failed: ' . $line . '. Check **History**; if the status does not change, an advisor can investigate.';
        } else {
            $reply = $fr
                ? 'Aucun transfert bloqué n’apparaît sur votre compte. Ouvrez **Historique** pour le détail ; je peux aussi vous passer un conseiller.'
                : 'No stuck transfer is visible on your account. Open **History** for details; I can also connect you with an advisor.';
        }

        return [
            $reply,
            'transfer',
            $fr ? 'Transfert bloqué' : 'Stuck transfer',
            $fr
                ? ['Quels sont les délais ?', 'Voir mes transactions', $agent]
                : ['What are the timelines?', 'See my transactions', $agent],
        ];
    }

    private static function replyTransferDelay(bool $fr, string $agent): array
    {
        $reply = $fr
            ? 'Délais habituels : **mobile money** quelques minutes, **virement bancaire** 1 jour ouvré, **crypto** selon le réseau. Un contrôle complémentaire peut allonger le délai.'
            : 'Typical timelines: **mobile money** a few minutes, **bank transfer** 1 business day, **crypto** depends on the network. An extra compliance check can add delay.';

        return [
            $reply,
            'transfer',
            $fr ? 'Délais de transfert' : 'Transfer timelines',
            $fr
                ? ['Mon transfert est bloqué', 'Quelles devises sont supportées ?', $agent]
                : ['My transfer is stuck', 'Which currencies are supported?', $agent],
        ];
    }

    private static function replyTransferCurrencies(bool $fr, string $agent): array
    {
        $reply = $fr
            ? 'Devises : **EUR**, **USD**, **GBP**, **XAF**, **XOF**, **USDT**, **USDC**. Rails : **mobile_money**, **bank**, **crypto**, **cash_pickup**. Choisissez la devise dans **Envoyer**.'
            : 'Currencies: **EUR**, **USD**, **GBP**, **XAF**, **XOF**, **USDT**, **USDC**. Rails: **mobile_money**, **bank**, **crypto**, **cash_pickup**. Pick the currency in **Send**.';

        return [
            $reply,
            'transfer',
            $fr ? 'Devises supportées' : 'Supported currencies',
            $fr
                ? ['Comment envoyer ?', 'Frais à l’international', $agent]
                : ['How do I send?', 'International fees', $agent],
        ];
    }

    private static function replyBalance(array $ctx, bool $fr, string $hello, string $agent): array
    {
        $lines = self::walletLines($ctx, $fr);
        if ($lines === []) {
            $reply = $fr
                ? $hello . '. Je n’ai pas de solde à afficher ici. Ouvrez **Portefeuille** pour voir vos devises (disponible, en attente, en transit).'
                : $hello . '. I have no balances to show here. Open **Wallet** to see your currencies (available, pending, in transit).';
        } else {
            $list = implode(' ; ', $lines);
            $reply = $fr
                ? $hello . '. Voici vos soldes : ' . $list . '. Le détail reste dans **Portefeuille**.'
                : $hello . '. Your balances: ' . $list . '. Full detail is in **Wallet**.';
        }

        return [
            $reply,
            'account',
            $fr ? 'Question sur le solde' : 'Balance question',
            $fr
                ? ['Je vois un écart sur mon solde', 'Comment fonctionnent les wallets ?', $agent]
                : ['I see a gap on my balance', 'How do wallets work?', $agent],
        ];
    }

    private static function replyBalanceGap(array $ctx, bool $fr, string $agent): array
    {
        $pendingBits = [];
        foreach (self::wallets($ctx) as $w) {
            $cur = strtoupper((string) ($w['currency'] ?? ''));
            $pending = (float) ($w['pending_balance'] ?? 0);
            $transit = (float) ($w['in_transit_balance'] ?? 0);
            if ($pending > 0) {
                $pendingBits[] = $fr
                    ? '**' . $cur . '** ' . self::money($pending) . ' en attente'
                    : '**' . $cur . '** ' . self::money($pending) . ' pending';
            }
            if ($transit > 0) {
                $pendingBits[] = $fr
                    ? '**' . $cur . '** ' . self::money($transit) . ' en transit'
                    : '**' . $cur . '** ' . self::money($transit) . ' in transit';
            }
        }
        $cite = $pendingBits !== [] ? ' ' . ($fr ? 'Actuellement : ' : 'Currently: ') . implode(', ', $pendingBits) . '.' : '';
        $reply = $fr
            ? 'Le **disponible** est utilisable tout de suite ; **en attente** n’est pas encore libéré (KYC, compensation) ; **en transit** est un envoi en cours.' . $cite . ' Voyez **Portefeuille**.'
            : '**Available** can be used now; **pending** is not yet released (KYC, clearing); **in transit** is a transfer still moving.' . $cite . ' See **Wallet**.';

        return [
            $reply,
            'account',
            $fr ? 'Écart de solde' : 'Balance gap',
            $fr ? ['Quel est mon solde ?', 'Mon transfert est bloqué', $agent] : ['What is my balance?', 'My transfer is stuck', $agent],
        ];
    }

    private static function replyAccountBlocked(array $ctx, bool $fr, string $agent): array
    {
        $st = strtolower(trim((string) ($ctx['status'] ?? '')));
        $blockedOnOurSide = $st !== '' && !in_array($st, ['active', 'enabled', 'ok', 'verified'], true);
        if ($blockedOnOurSide) {
            $reply = $fr
                ? 'Votre compte n’est pas au statut **actif** (statut : ' . $st . '). Un conseiller peut vous indiquer la marche à suivre.'
                : 'Your account is not **active** (status: ' . $st . '). An advisor can explain the next steps.';
        } else {
            $reply = $fr
                ? 'De notre côté, votre compte n’apparaît pas bloqué. Si une opération est refusée, précisez laquelle ou parlez à un conseiller.'
                : 'On our side, your account does not appear blocked. If an operation was refused, tell me which one or speak to an advisor.';
        }

        return [
            $reply,
            'account',
            $fr ? 'Compte bloqué' : 'Blocked account',
            $fr ? ['Question sur mon solde', 'Vérification KYC', $agent] : ['Question about my balance', 'KYC verification', $agent],
        ];
    }

    private static function replyKyc(array $ctx, bool $fr, string $agent): array
    {
        $status = self::kycCite($ctx, $fr);
        $reply = $fr
            ? 'Pour le **KYC** : pièce d’identité + selfie, traitement en **24-48h**. ' . $status . ' Déposez ou corrigez le dossier dans **KYC**.'
            : 'For **KYC**: ID document + selfie, reviewed in **24-48h**. ' . $status . ' Submit or fix the file in **KYC**.';

        return [
            $reply,
            'kyc',
            $fr ? 'Vérification KYC' : 'KYC verification',
            $fr
                ? ['Quels documents sont acceptés ?', 'Ma vérification est en attente', 'Mon dossier a été refusé', $agent]
                : ['Which documents are accepted?', 'My verification is pending', 'My file was rejected', $agent],
        ];
    }

    private static function replyKycDocs(bool $fr, string $agent): array
    {
        $reply = $fr
            ? 'Documents acceptés : **passeport**, **CNI**, **titre de séjour**, plus un **selfie**. Photos nettes, coins visibles — pas de scans flous.'
            : 'Accepted documents: **passport**, **national ID**, **residence permit**, plus a **selfie**. Sharp photos, corners visible — no blurry scans.';

        return [
            $reply,
            'kyc',
            $fr ? 'Documents KYC' : 'KYC documents',
            $fr ? ['Ma vérification est en attente', 'Mon dossier a été refusé', $agent] : ['My verification is pending', 'My file was rejected', $agent],
        ];
    }

    private static function replyKycPending(array $ctx, bool $fr, string $agent): array
    {
        $status = self::kycCite($ctx, $fr);
        $reply = $fr
            ? 'Votre dossier est **en cours**. ' . $status . ' Comptez 24-48h ; nous vous prévenons dès qu’il est traité.'
            : 'Your file is **in progress**. ' . $status . ' Allow 24-48h; we notify you as soon as it is reviewed.';

        return [
            $reply,
            'kyc',
            $fr ? 'KYC en attente' : 'KYC pending',
            $fr ? ['Quels documents sont acceptés ?', $agent] : ['Which documents are accepted?', $agent],
        ];
    }

    private static function replyKycRejected(array $ctx, bool $fr, string $agent): array
    {
        $status = self::kycCite($ctx, $fr);
        $reply = $fr
            ? 'Votre dossier a été **refusé** ou est **à corriger**. ' . $status . ' Rechargez des pièces nettes dans **KYC** (passeport, CNI ou titre de séjour + selfie).'
            : 'Your file was **rejected** or needs **correction**. ' . $status . ' Upload clear documents in **KYC** (passport, national ID or residence permit + selfie).';

        return [
            $reply,
            'kyc',
            $fr ? 'KYC refusé' : 'KYC rejected',
            $fr ? ['Quels documents sont acceptés ?', $agent] : ['Which documents are accepted?', $agent],
        ];
    }

    private static function replyFees(bool $fr, string $agent): array
    {
        $reply = $fr
            ? 'Les **frais** s’affichent **avant confirmation**. Ils dépendent du provider et du rail (mobile money, banque, crypto). Rien n’est prélevé en plus après votre validation.'
            : '**Fees** are shown **before you confirm**. They depend on the provider and rail (mobile money, bank, crypto). Nothing extra is charged after you confirm.';

        return [
            $reply,
            'billing',
            $fr ? 'Question sur les frais' : 'Question about fees',
            $fr
                ? ['Frais pour l’international', 'Pourquoi des frais sont-ils prélevés ?', $agent]
                : ['International fees', 'Why are fees charged?', $agent],
        ];
    }

    private static function replyFeesIntl(bool $fr, string $agent): array
    {
        $reply = $fr
            ? 'À l’international : **spread de change** + **frais de rail**. Le total est visible avant confirmation — pas de frais cachés ensuite.'
            : 'International: **FX spread** + **rail fee**. The total is visible before confirmation — no hidden fee afterwards.';

        return [
            $reply,
            'billing',
            $fr ? 'Frais internationaux' : 'International fees',
            $fr ? ['Pourquoi des frais sont-ils prélevés ?', 'Quelles devises sont supportées ?', $agent] : ['Why are fees charged?', 'Which currencies are supported?', $agent],
        ];
    }

    private static function replyFeesWhy(bool $fr, string $agent): array
    {
        $reply = $fr
            ? 'Les frais couvrent le **spread FX** et le **rail** (opérateur, banque ou réseau crypto). Après confirmation, aucun frais caché n’est ajouté.'
            : 'Fees cover the **FX spread** and the **rail** (operator, bank or crypto network). After confirmation, no hidden fee is added.';

        return [
            $reply,
            'billing',
            $fr ? 'Pourquoi des frais' : 'Why fees',
            $fr ? ['Frais pour l’international', 'Détail avant confirmation', $agent] : ['International fees', 'Detail before confirmation', $agent],
        ];
    }

    private static function replyPassword(bool $fr, string $agent): array
    {
        $reply = $fr
            ? 'Pour réinitialiser le mot de passe, utilisez **Mot de passe oublié** sur **/login**. Ne collez jamais votre mot de passe dans ce chat.'
            : 'To reset your password, use **Forgot password** on **/login**. Never paste your password in this chat.';

        return [
            $reply,
            'account',
            $fr ? 'Mot de passe / connexion' : 'Password / login',
            $fr ? ['Je n’arrive pas à me connecter', $agent] : ['I cannot sign in', $agent],
        ];
    }

    private static function replyBeneficiaries(bool $fr, string $agent): array
    {
        $reply = $fr
            ? 'Gérez vos contacts dans **Destinataires** / **Bénéficiaires**, puis sélectionnez-les depuis **Envoyer**.'
            : 'Manage contacts in **Recipients** / **Beneficiaries**, then pick them from **Send**.';

        return [
            $reply,
            'transfer',
            $fr ? 'Destinataires' : 'Beneficiaries',
            $fr ? ['Comment envoyer ?', $agent] : ['How do I send?', $agent],
        ];
    }

    private static function replyLimits(array $ctx, bool $fr, string $agent): array
    {
        $level = strtolower(trim((string) ($ctx['kyc_level'] ?? '')));
        $unverified = $level === '' || in_array($level, ['unverified', 'none', '0', 'level_0'], true);
        if ($fr) {
            $reply = 'Les **plafonds** dépendent de votre niveau **KYC**.';
            $reply .= $unverified
                ? ' Compte non vérifié : limites réduites. Validez votre identité pour les augmenter.'
                : ' Niveau actuel : **' . ($ctx['kyc_level'] ?? $level) . '**. Un niveau supérieur débloque des plafonds plus élevés.';
        } else {
            $reply = '**Limits** depend on your **KYC** level.';
            $reply .= $unverified
                ? ' Unverified accounts have reduced limits. Complete identity verification to raise them.'
                : ' Current level: **' . ($ctx['kyc_level'] ?? $level) . '**. A higher level unlocks larger limits.';
        }

        return [
            $reply,
            'account',
            $fr ? 'Plafonds' : 'Limits',
            $fr ? ['Vérification KYC', $agent] : ['KYC verification', $agent],
        ];
    }

    private static function replyTransactions(array $ctx, bool $fr, string $agent): array
    {
        $tx = self::lastTxLine($ctx, $fr);
        $reply = $fr
            ? 'Toutes les opérations sont dans **Historique** du tableau de bord.'
            : 'All operations are in **History** on the dashboard.';
        if ($tx !== null) {
            $reply .= $fr ? ' Dernière : ' . $tx . '.' : ' Latest: ' . $tx . '.';
        }

        return [
            $reply,
            'transfer',
            $fr ? 'Historique des transactions' : 'Transaction history',
            $fr ? ['Mon transfert est bloqué', 'Quel est mon solde ?', $agent] : ['My transfer is stuck', 'What is my balance?', $agent],
        ];
    }

    private static function replyBusiness(array $ctx, bool $fr, string $agent): array
    {
        $kyb = trim((string) ($ctx['kyb_status'] ?? ''));
        $extra = $kyb !== ''
            ? ($fr ? ' Statut KYB actuel : **' . $kyb . '**.' : ' Current KYB status: **' . $kyb . '**.')
            : '';
        $reply = $fr
            ? 'Le **tableau de bord Business** sert aux entreprises : **KYB**, équipe et rôles.' . $extra
            : 'The **Business dashboard** is for companies: **KYB**, team and roles.' . $extra;

        return [
            $reply,
            'account',
            $fr ? 'Compte Business' : 'Business account',
            $fr ? ['Nexus Connect / API', $agent] : ['Nexus Connect / API', $agent],
        ];
    }

    private static function replyConnect(bool $fr, string $agent): array
    {
        $reply = $fr
            ? '**Nexus Connect** expose l’API et la gestion des employés / intégrations. La documentation et les clés se trouvent dans l’espace Connect.'
            : '**Nexus Connect** exposes the API and employee / integration management. Docs and keys live in the Connect area.';

        return [
            $reply,
            'other',
            'Nexus Connect',
            $fr ? ['Compte Business', $agent] : ['Business account', $agent],
        ];
    }

    private static function replyHours(bool $fr, string $agent): array
    {
        $reply = $fr
            ? 'L’assistant est disponible **24/7**. Les conseillers humains répondent en semaine, environ **8h–19h** (Africa/Douala).'
            : 'The assistant is available **24/7**. Human advisors reply on weekdays, about **8:00–19:00** (Africa/Douala).';

        return [
            $reply,
            'other',
            $fr ? 'Horaires du support' : 'Support hours',
            [$agent],
        ];
    }

    private static function replyGreeting(array $ctx, bool $fr, string $hello, string $agent): array
    {
        $reply = $fr
            ? $hello . '. Je suis l’**assistant Nexus**. Je peux vous aider sur : transferts, solde, KYC, frais, ou un conseiller.'
            : $hello . '. I am the **Nexus assistant**. I can help with transfers, balance, KYC, fees, or an advisor.';

        return [
            $reply,
            'other',
            $fr ? 'Menu d\'aide' : 'Help menu',
            $fr
                ? ['Je veux envoyer de l\'argent', 'Question sur mon solde', 'Vérification KYC', 'Mes frais', $agent]
                : ['I want to send money', 'Question about my balance', 'KYC verification', 'My fees', $agent],
        ];
    }

    private static function replyThanks(bool $fr, string $agent): array
    {
        $reply = $fr
            ? 'Avec plaisir. Dites-moi si une autre question se présente.'
            : 'Glad it helped. Tell me if another question comes up.';

        return [
            $reply,
            'other',
            $fr ? 'Remerciement' : 'Thanks',
            $fr
                ? ['J’ai une autre question', 'Comment voir mes transactions ?']
                : ['I have another question', 'How do I see my transactions?'],
        ];
    }

    private static function replyUnknown(bool $escalate, bool $fr, string $agent, string $message): array
    {
        if ($escalate) {
            $reply = $fr
                ? 'Je n’ai pas bien compris. Je vous connecte à un conseiller qui pourra vous aider plus précisément.'
                : 'I still don’t quite understand. I am connecting you with an advisor who can help more precisely.';

            return [$reply, 'other', mb_substr($message, 0, 120), []];
        }

        $reply = $fr
            ? 'Je ne suis pas sûr de comprendre. Pouvez-vous **préciser** s’il s’agit d’un transfert, de votre solde, du KYC ou des frais ?'
            : 'I am not sure I understand. Could you **clarify** whether this is about a transfer, your balance, KYC, or fees?';

        return [
            $reply,
            'other',
            mb_substr($message, 0, 120),
            $fr
                ? ['Transferts', 'Solde', 'KYC', 'Frais', $agent]
                : ['Transfers', 'Balance', 'KYC', 'Fees', $agent],
        ];
    }

    // ─── Context helpers ──────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private static function wallets(array $ctx): array
    {
        $w = $ctx['wallets'] ?? [];

        return is_array($w) ? $w : [];
    }

    /** @return list<string> */
    private static function walletLines(array $ctx, bool $fr): array
    {
        $out = [];
        foreach (self::wallets($ctx) as $w) {
            if (!is_array($w)) {
                continue;
            }
            $cur = strtoupper(trim((string) ($w['currency'] ?? '')));
            if ($cur === '') {
                continue;
            }
            $avail = $w['available_balance'] ?? ($w['balance'] ?? 0);
            $line = '**' . $cur . '** ' . self::money($avail) . ($fr ? ' disponible' : ' available');
            $pending = (float) ($w['pending_balance'] ?? 0);
            $transit = (float) ($w['in_transit_balance'] ?? 0);
            if ($pending > 0) {
                $line .= $fr ? ', ' . self::money($pending) . ' en attente' : ', ' . self::money($pending) . ' pending';
            }
            if ($transit > 0) {
                $line .= $fr ? ', ' . self::money($transit) . ' en transit' : ', ' . self::money($transit) . ' in transit';
            }
            $out[] = $line;
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private static function lastTx(array $ctx): ?array
    {
        $txs = $ctx['transactions'] ?? [];
        if (!is_array($txs) || $txs === [] || !is_array($txs[0] ?? null)) {
            return null;
        }

        return $txs[0];
    }

    private static function lastTxLine(array $ctx, bool $fr): ?string
    {
        $tx = self::lastTx($ctx);

        return $tx === null ? null : self::formatTx($tx, $fr);
    }

    /** @param array<string,mixed> $tx */
    private static function formatTx(array $tx, bool $fr): string
    {
        $amount = self::money($tx['amount'] ?? 0);
        $cur = strtoupper((string) ($tx['currency'] ?? ''));
        $st = (string) ($tx['status'] ?? '');
        $dest = trim((string) ($tx['destination'] ?? ''));
        $label = trim((string) ($tx['label'] ?? ''));
        $bits = array_filter([$amount . ($cur !== '' ? ' ' . $cur : ''), $st !== '' ? $st : null]);
        $line = implode(', ', $bits);
        if ($dest !== '') {
            $line .= $fr ? ' vers ' . $dest : ' to ' . $dest;
        } elseif ($label !== '') {
            $line .= ' — ' . $label;
        }

        return $line;
    }

    /** @return array<string,mixed>|null */
    private static function stuckTx(array $ctx): ?array
    {
        $txs = $ctx['transactions'] ?? [];
        if (!is_array($txs)) {
            return null;
        }
        $bad = ['processing', 'pending', 'failed', 'error', 'blocked', 'in_transit', 'in-progress', 'in_progress'];
        foreach ($txs as $tx) {
            if (!is_array($tx)) {
                continue;
            }
            $st = strtolower((string) ($tx['status'] ?? ''));
            if (in_array($st, $bad, true)) {
                return $tx;
            }
        }

        return null;
    }

    private static function kycCite(array $ctx, bool $fr): string
    {
        $level = trim((string) ($ctx['kyc_level'] ?? ''));
        $row = is_array($ctx['kyc'] ?? null) ? $ctx['kyc'] : null;
        $st = is_array($row) ? strtolower((string) ($row['status'] ?? '')) : '';
        $reason = is_array($row) ? trim((string) ($row['reason'] ?? '')) : '';
        $mapped = self::mapKycStatus($st, $fr);
        $parts = [];
        if ($level !== '') {
            $parts[] = $fr ? 'Niveau KYC : **' . $level . '**' : 'KYC level: **' . $level . '**';
        }
        if ($mapped !== '') {
            $parts[] = $fr ? 'dernier dossier : **' . $mapped . '**' : 'latest file: **' . $mapped . '**';
        }
        if ($reason !== '' && in_array($st, ['rejected', 'refused', 'resubmission_requested'], true)) {
            $parts[] = $fr ? 'motif : ' . $reason : 'reason: ' . $reason;
        }
        if ($parts === []) {
            return $fr ? 'Je n’ai pas de dossier KYC à citer ici.' : 'I have no KYC file to cite here.';
        }

        return implode(', ', $parts) . '.';
    }

    private static function mapKycStatus(string $status, bool $fr): string
    {
        $status = strtolower($status);
        $frMap = [
            'verified' => 'validé',
            'approved' => 'validé',
            'pending' => 'en cours',
            'in_progress' => 'en cours',
            'in-progress' => 'en cours',
            'submitted' => 'en cours',
            'rejected' => 'refusé',
            'refused' => 'refusé',
            'resubmission_requested' => 'à corriger',
            'resubmit' => 'à corriger',
        ];
        $enMap = [
            'verified' => 'verified',
            'approved' => 'verified',
            'pending' => 'in progress',
            'in_progress' => 'in progress',
            'in-progress' => 'in progress',
            'submitted' => 'in progress',
            'rejected' => 'rejected',
            'refused' => 'rejected',
            'resubmission_requested' => 'needs correction',
            'resubmit' => 'needs correction',
        ];
        $map = $fr ? $frMap : $enMap;

        return $map[$status] ?? ($status !== '' ? $status : '');
    }

    /** @param list<mixed> $history */
    private static function cleanHistory(array $history): array
    {
        $out = [];
        foreach ($history as $h) {
            if (!is_array($h)) {
                continue;
            }
            $out[] = [
                'sender' => (string) ($h['sender'] ?? 'customer'),
                'body'   => (string) ($h['body'] ?? ''),
            ];
        }

        return $out;
    }
}
