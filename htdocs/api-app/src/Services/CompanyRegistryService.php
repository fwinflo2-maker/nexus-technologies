<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Kyc\KycStatus;
use Nexus\Kyc\KycSubjectType;
use Nexus\Kyc\SumsubAdapter;
use Nexus\Providers\OpenCorporatesClient;
use PDO;
use RuntimeException;

/**
 * Vérification d'entreprise via registre OpenCorporates.
 *
 * Usage :
 *   - recherche / enrichissement (GET) ;
 *   - fallback KYB quand Sumsub n'a pas de level Company configuré.
 *
 * Règles de match (strictes) :
 *   1. numéro d'immatriculation (normalisé) égal ;
 *   2. juridiction = pays de résidence (ISO-2 → jurisdiction_code) ;
 *   3. société non inactive.
 *
 * Le match registre débloque `kyb_status=verified` avec provider=opencorporates
 * dans `kyc_verifications` + audit_logs. Ce n'est PAS une KYB UBO complète.
 */
final class CompanyRegistryService
{
    public function __construct(
        private readonly OpenCorporatesClient $client = new OpenCorporatesClient(),
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * True si le fallback registre doit être proposé (OC configuré + level KYB Sumsub absent).
     */
    public function isFallbackPreferred(): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        try {
            (new SumsubAdapter())->levelName(KycSubjectType::COMPANY);
            return false;
        } catch (RuntimeException) {
            return true;
        }
    }

    /**
     * @param array{q?:string,jurisdiction_code?:string,country_code?:string,page?:int,per_page?:int} $filters
     * @return array{companies:list<array<string,mixed>>,total_count:int,page:int,per_page:int}
     */
    public function search(array $filters): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('OPENCORPORATES_NOT_CONFIGURED');
        }

        // Préférer les sociétés actives sauf demande contraire.
        if (!isset($filters['inactive'])) {
            $filters['inactive'] = 'false';
        }

        return $this->client->search($filters);
    }

    /** @return array<string,mixed> */
    public function getCompany(string $jurisdictionCode, string $companyNumber): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('OPENCORPORATES_NOT_CONFIGURED');
        }

        return $this->client->getCompany($jurisdictionCode, $companyNumber);
    }

    /**
     * Vérifie une société contre le profil business de l'utilisateur et
     * promeut `kyb_status` si le match est valide.
     *
     * @return array{
     *   verified:bool,
     *   company:array<string,mixed>,
     *   account:array<string,mixed>|null,
     *   match:array{number:bool,jurisdiction:bool,active:bool,name_hint:bool}
     * }
     */
    public function verifyForUser(
        PDO $pdo,
        int $userId,
        string $jurisdictionCode,
        string $companyNumber,
        ?string $ipAddress = null
    ): array {
        if (!$this->isConfigured()) {
            throw new RuntimeException('OPENCORPORATES_NOT_CONFIGURED');
        }

        $profile = $this->loadBusinessProfile($pdo, $userId);
        if ($profile === null) {
            throw new RuntimeException('COMPANY_REGISTRY_USER_NOT_FOUND');
        }
        if (($profile['account_type'] ?? '') !== 'business') {
            throw new RuntimeException('COMPANY_REGISTRY_NOT_BUSINESS');
        }
        if (($profile['kyb_status'] ?? '') === 'verified') {
            $company = $this->client->getCompany($jurisdictionCode, $companyNumber);
            return [
                'verified' => true,
                'company'  => $company,
                'account'  => $this->accountProjection($profile),
                'match'    => [
                    'number'       => true,
                    'jurisdiction' => true,
                    'active'       => true,
                    'name_hint'    => true,
                ],
            ];
        }

        $expectedNumber = self::normalizeNumber((string) ($profile['company_registration_number'] ?? ''));
        if ($expectedNumber === '') {
            throw new RuntimeException('COMPANY_REGISTRY_NUMBER_MISSING');
        }

        $expectedJurisdiction = self::jurisdictionFromCountry((string) ($profile['country_of_residence'] ?? ''));
        if ($expectedJurisdiction === null) {
            throw new RuntimeException('COMPANY_REGISTRY_COUNTRY_MISSING');
        }

        $company = $this->client->getCompany($jurisdictionCode, $companyNumber);

        $gotNumber = self::normalizeNumber((string) ($company['company_number'] ?? ''));
        $gotJurisdiction = strtolower(trim((string) ($company['jurisdiction_code'] ?? '')));
        $numberOk = $gotNumber !== '' && $gotNumber === $expectedNumber;
        $jurisdictionOk = $gotJurisdiction === strtolower(trim($jurisdictionCode))
            && $gotJurisdiction === $expectedJurisdiction;
        $activeOk = !((bool) ($company['inactive'] ?? false));

        // Indices de nom (non bloquant seul) — aide l'audit.
        $nameHint = self::namesLooselyMatch(
            (string) ($profile['company_name'] ?? ''),
            (string) ($company['name'] ?? '')
        );

        $match = [
            'number'       => $numberOk,
            'jurisdiction' => $jurisdictionOk,
            'active'       => $activeOk,
            'name_hint'    => $nameHint,
        ];

        if (!$numberOk || !$jurisdictionOk || !$activeOk) {
            $this->audit($pdo, $userId, 'kyb.registry.reject', [
                'provider'           => 'opencorporates',
                'jurisdiction_code'  => $gotJurisdiction,
                'company_number'     => $gotNumber,
                'match'              => $match,
                'opencorporates_url' => $company['opencorporates_url'] ?? null,
            ], $ipAddress);

            throw new RuntimeException('COMPANY_REGISTRY_MISMATCH');
        }

        $this->promoteKyb($pdo, $userId, $company);
        $this->upsertVerification($pdo, $userId, $company);
        $this->audit($pdo, $userId, 'kyb.registry.verify', [
            'provider'           => 'opencorporates',
            'jurisdiction_code'  => $gotJurisdiction,
            'company_number'     => $gotNumber,
            'company_name'       => $company['name'] ?? null,
            'current_status'     => $company['current_status'] ?? null,
            'opencorporates_url' => $company['opencorporates_url'] ?? null,
            'match'              => $match,
            'note'               => 'Registry match only — not full UBO/representative KYB',
        ], $ipAddress);

        $fresh = $this->loadBusinessProfile($pdo, $userId);

        return [
            'verified' => true,
            'company'  => $company,
            'account'  => $fresh !== null ? $this->accountProjection($fresh) : null,
            'match'    => $match,
        ];
    }

    /** ISO-2 (ou déjà un jurisdiction_code) → code OpenCorporates minuscule. */
    public static function jurisdictionFromCountry(string $countryOrJurisdiction): ?string
    {
        $raw = strtolower(trim($countryOrJurisdiction));
        if ($raw === '') {
            return null;
        }
        // Déjà un code juridiction (ex. us_de, gb, fr).
        if (preg_match('/^[a-z]{2}(_[a-z0-9]+)?$/', $raw) === 1) {
            return $raw;
        }
        // ISO-2 seul.
        if (preg_match('/^[a-z]{2}$/', $raw) === 1) {
            return $raw;
        }
        return null;
    }

    public static function normalizeNumber(string $number): string
    {
        // Conserve alphanumérique ; retire espaces / ponctuation (SIREN, RCCM…).
        $n = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $number) ?? '');
        return $n;
    }

    public static function namesLooselyMatch(string $a, string $b): bool
    {
        $na = self::normalizeName($a);
        $nb = self::normalizeName($b);
        if ($na === '' || $nb === '') {
            return false;
        }
        if ($na === $nb) {
            return true;
        }
        return str_contains($na, $nb) || str_contains($nb, $na);
    }

    private static function normalizeName(string $name): string
    {
        $n = mb_strtolower(trim($name), 'UTF-8');
        $n = preg_replace('/\b(sarl|sa|sas|sasu|ltd|limited|llc|inc|corp|gmbh|bv|nv)\b/u', '', $n) ?? $n;
        $n = preg_replace('/[^a-z0-9]+/u', '', $n) ?? $n;
        return $n;
    }

    /** @return array<string,mixed>|null */
    private function loadBusinessProfile(PDO $pdo, int $userId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, account_type, status, kyc_level, kyb_status, kyc_verified_at, kyb_verified_at,
                    company_name, company_registration_number, country_of_residence
               FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $profile */
    private function accountProjection(array $profile): array
    {
        return [
            'status'          => (string) ($profile['status'] ?? 'PENDING'),
            'kyc_level'       => (string) ($profile['kyc_level'] ?? 'none'),
            'kyb_status'      => (string) ($profile['kyb_status'] ?? 'none'),
            'kyc_verified_at' => $profile['kyc_verified_at'] ?? null,
            'kyb_verified_at' => $profile['kyb_verified_at'] ?? null,
        ];
    }

    /** @param array<string,mixed> $company */
    private function promoteKyb(PDO $pdo, int $userId, array $company): void
    {
        $pdo->prepare(
            'UPDATE users
                SET kyb_status = :st,
                    kyb_verified_at = NOW(),
                    status = IF(status = \'PENDING\', \'ACTIVE\', status),
                    company_name = COALESCE(NULLIF(company_name, \'\'), :name)
              WHERE id = :id'
        )->execute([
            'st'   => KycStatus::VERIFIED->value,
            'name' => (string) ($company['name'] ?? ''),
            'id'   => $userId,
        ]);
    }

    /** @param array<string,mixed> $company */
    private function upsertVerification(PDO $pdo, int $userId, array $company): void
    {
        $applicantId = strtolower((string) ($company['jurisdiction_code'] ?? ''))
            . '/' . (string) ($company['company_number'] ?? '');
        $reason = 'OpenCorporates registry match'
            . (isset($company['current_status']) ? ' (' . $company['current_status'] . ')' : '');

        // applicant_id UNIQUE (provider, env, applicant) — prefix user si collision multi-compte.
        $applicantId = substr($userId . ':' . $applicantId, 0, 128);

        $stmt = $pdo->prepare(
            'INSERT INTO kyc_verifications
                (user_id, provider, environment, subject_type, applicant_id, level_name, status, reason, reviewed_at)
             VALUES
                (:uid, \'opencorporates\', :env, \'company\', :aid, \'registry\', :st, :reason, NOW())
             ON DUPLICATE KEY UPDATE
                applicant_id = VALUES(applicant_id),
                status = VALUES(status),
                reason = VALUES(reason),
                reviewed_at = NOW(),
                updated_at = NOW()'
        );
        $stmt->execute([
            'uid'    => $userId,
            'env'    => (getenv('APP_ENV') === 'production') ? 'production' : 'sandbox',
            'aid'    => $applicantId,
            'st'     => KycStatus::VERIFIED->value,
            'reason' => substr($reason, 0, 500),
        ]);
    }

    /** @param array<string,mixed> $metadata */
    private function audit(PDO $pdo, int $userId, string $action, array $metadata, ?string $ip): void
    {
        $pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, metadata, ip_address)
             VALUES (:uid, :action, \'user\', :eid, :meta, :ip)'
        )->execute([
            'uid'    => $userId,
            'action' => $action,
            'eid'    => $userId,
            'meta'   => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip'     => $ip,
        ]);
    }
}
