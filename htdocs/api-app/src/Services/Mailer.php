<?php

declare(strict_types=1);

namespace Nexus\Services;

/**
 * Envoi d'e-mails transactionnels (confirmation de compte, reset mot de passe).
 *
 * Transports :
 *   1. SMTP si MAIL_HOST est renseigné (MAIL_PORT / USERNAME / PASSWORD / ENCRYPTION).
 *   2. Fichier `storage/mail/*.html` en development (toujours, en plus du SMTP).
 *   3. Production sans SMTP : l'envoi échoue (fail-closed, journalisé).
 *
 * Aucune dépendance Composer : socket SMTP natif.
 */
final class Mailer
{
    private function __construct()
    {
    }

    public static function isDevelopment(): bool
    {
        return defined('APP_ENV') && APP_ENV === 'development';
    }

    /** Origine du frontend pour les liens des e-mails (sans slash final). */
    public static function frontendBaseUrl(): string
    {
        $explicit = trim((string) (getenv('APP_FRONTEND_URL') ?: ''));
        if ($explicit !== '') {
            return rtrim($explicit, '/');
        }

        if (defined('APP_ORIGINS') && is_array(APP_ORIGINS) && APP_ORIGINS !== []) {
            return rtrim((string) APP_ORIGINS[0], '/');
        }

        return 'http://localhost:5173';
    }

    /**
     * @return array{sent:bool,driver:string,path:?string}
     */
    public static function sendPasswordReset(string $to, string $name, string $resetUrl, int $ttlMinutes): array
    {
        $safeName = self::plain($name !== '' ? $name : 'bonjour');
        $subject = 'Réinitialisation de votre mot de passe Nexus';
        $intro = 'Vous avez demandé à réinitialiser le mot de passe de votre compte Nexus.';
        $cta = 'Choisir un nouveau mot de passe';
        $hint = 'Ce lien expire dans ' . $ttlMinutes . ' minutes et ne peut être utilisé qu\'une fois.';

        return self::send(
            $to,
            $subject,
            self::htmlTemplate($safeName, $intro, $cta, $resetUrl, $hint),
            self::textTemplate($safeName, $intro, $resetUrl, $hint)
        );
    }

    /**
     * @return array{sent:bool,driver:string,path:?string}
     */
    public static function sendEmailVerification(string $to, string $name, string $verifyUrl, int $ttlHours): array
    {
        $safeName = self::plain($name !== '' ? $name : 'bonjour');
        $subject = 'Confirmez votre adresse e-mail Nexus';
        $intro = 'Confirmez votre adresse e-mail pour activer votre compte Nexus.';
        $cta = 'Confirmer mon e-mail';
        $hint = 'Ce lien expire dans ' . $ttlHours . ' heure' . ($ttlHours > 1 ? 's' : '') . '.';

        return self::send(
            $to,
            $subject,
            self::htmlTemplate($safeName, $intro, $cta, $verifyUrl, $hint),
            self::textTemplate($safeName, $intro, $verifyUrl, $hint)
        );
    }

    /**
     * Message de bienvenue après création / confirmation de compte.
     *
     * @return array{sent:bool,driver:string,path:?string}
     */
    public static function sendWelcome(string $to, string $name, string $accountType = 'personal'): array
    {
        $safeName = self::plain($name !== '' ? $name : 'bonjour');
        $subject = 'Bienvenue chez Nexus Technologies';
        $appUrl = self::frontendBaseUrl() . '/login';
        $kind = $accountType === 'business'
            ? 'votre compte entreprise'
            : 'votre compte personnel';
        $intro = 'Nous confirmons la création de ' . $kind . ' Nexus. Votre espace est prêt : vous pouvez y compléter votre profil et, le cas échéant, votre vérification d\'identité avant d\'effectuer des virements.';
        $cta = 'Accéder à Nexus';
        $hint = 'Cet e-mail est envoyé automatiquement par noreply@nexustechnologies.cloud. Merci de ne pas y répondre. Pour toute question, utilisez le support depuis l\'application.';

        return self::send(
            $to,
            $subject,
            self::htmlTemplate($safeName, $intro, $cta, $appUrl, $hint),
            self::textTemplate($safeName, $intro, $appUrl, $hint)
        );
    }

    /**
     * @return array{sent:bool,driver:string,path:?string}
     */
    public static function send(string $to, string $subject, string $htmlBody, string $textBody = ''): array
    {
        $path = null;
        if (self::isDevelopment()) {
            $path = self::writeFile($to, $subject, $htmlBody);
        }

        $host = trim((string) (getenv('MAIL_HOST') ?: ''));
        if ($host !== '') {
            $ok = self::sendSmtp($to, $subject, $htmlBody, $textBody);
            if ($ok) {
                return ['sent' => true, 'driver' => 'smtp', 'path' => $path];
            }
            error_log('[NEXUS Mailer] Échec SMTP vers ' . $to);
            if (self::isDevelopment() && $path !== null) {
                return ['sent' => true, 'driver' => 'file', 'path' => $path];
            }

            return ['sent' => false, 'driver' => 'smtp', 'path' => $path];
        }

        if (self::isDevelopment() && $path !== null) {
            return ['sent' => true, 'driver' => 'file', 'path' => $path];
        }

        error_log('[NEXUS Mailer] MAIL_HOST absent : e-mail non envoyé à ' . $to);

        return ['sent' => false, 'driver' => 'none', 'path' => $path];
    }

    private static function writeFile(string $to, string $subject, string $htmlBody): ?string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $dir = $base . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'mail';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            error_log('[NEXUS Mailer] Impossible de créer ' . $dir);

            return null;
        }

        $safeTo = preg_replace('/[^a-z0-9._+-]+/i', '_', $to) ?: 'recipient';
        $file = $dir . DIRECTORY_SEPARATOR . gmdate('Ymd_His') . '_' . $safeTo . '.html';
        $ok = @file_put_contents(
            $file,
            "<!-- to: {$to} -->\n<!-- subject: {$subject} -->\n" . $htmlBody
        );

        return $ok === false ? null : $file;
    }

    private static function sendSmtp(string $to, string $subject, string $htmlBody, string $textBody): bool
    {
        $host = trim((string) getenv('MAIL_HOST'));
        $port = (int) (getenv('MAIL_PORT') ?: 587);
        $user = (string) (getenv('MAIL_USERNAME') ?: '');
        $pass = (string) (getenv('MAIL_PASSWORD') ?: '');
        $enc = strtolower(trim((string) (getenv('MAIL_ENCRYPTION') ?: 'tls')));
        $from = trim((string) (getenv('MAIL_FROM') ?: 'noreply@nexustechnologies.cloud'));
        $fromName = trim((string) (getenv('MAIL_FROM_NAME') ?: 'Nexus Technologies'));

        $remote = ($enc === 'ssl') ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
        $fp = @stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT);
        if ($fp === false) {
            error_log("[NEXUS Mailer] Connexion SMTP {$remote} : {$errstr} ({$errno})");

            return false;
        }
        stream_set_timeout($fp, 12);

        try {
            self::expect($fp, 220);
            self::command($fp, 'EHLO nexus.local', 250);
            if ($enc === 'tls') {
                self::command($fp, 'STARTTLS', 220);
                $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (!@stream_socket_enable_crypto($fp, true, $crypto)) {
                    throw new \RuntimeException('STARTTLS a échoué');
                }
                self::command($fp, 'EHLO nexus.local', 250);
            }
            if ($user !== '') {
                self::command($fp, 'AUTH LOGIN', 334);
                self::command($fp, base64_encode($user), 334);
                self::command($fp, base64_encode($pass), 235);
            }

            self::command($fp, 'MAIL FROM:<' . $from . '>', 250);
            self::command($fp, 'RCPT TO:<' . $to . '>', 250);
            self::command($fp, 'DATA', 354);

            $boundary = 'nexus_' . bin2hex(random_bytes(8));
            $headers = [
                'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
                'From: ' . self::encodeHeader($fromName) . ' <' . $from . '>',
                'To: <' . $to . '>',
                'Subject: ' . self::encodeHeader($subject),
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            ];
            $text = $textBody !== '' ? $textBody : strip_tags($htmlBody);
            $body = implode("\r\n", $headers) . "\r\n\r\n"
                . "--{$boundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($text))
                . "--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($htmlBody))
                . "--{$boundary}--\r\n.";

            fwrite($fp, $body . "\r\n");
            self::expect($fp, 250);
            self::command($fp, 'QUIT', 221);

            return true;
        } catch (\Throwable $e) {
            error_log('[NEXUS Mailer] SMTP : ' . $e->getMessage());

            return false;
        } finally {
            fclose($fp);
        }
    }

    /** @param resource $fp */
    private static function command($fp, string $line, int $expected): void
    {
        fwrite($fp, $line . "\r\n");
        self::expect($fp, $expected);
    }

    /** @param resource $fp */
    private static function expect($fp, int $expected): void
    {
        $last = '';
        while (($line = fgets($fp, 2048)) !== false) {
            $last = $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($last, 0, 3);
        if ($code !== $expected) {
            throw new \RuntimeException('SMTP ' . $expected . ' attendu, reçu : ' . trim($last));
        }
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private static function plain(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function htmlTemplate(string $name, string $intro, string $cta, string $url, string $hint): string
    {
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!DOCTYPE html><html lang="fr"><body style="margin:0;padding:32px;background:#0b1220;font-family:Segoe UI,Roboto,sans-serif;color:#e8eefc;">'
            . '<div style="max-width:560px;margin:0 auto;background:#121a2b;border:1px solid #243044;border-radius:16px;padding:32px;">'
            . '<p style="margin:0 0 8px;letter-spacing:.2em;font-size:11px;color:#7dd3c7;">NEXUS</p>'
            . '<h1 style="margin:0 0 16px;font-size:22px;color:#fff;">Bonjour ' . $name . '</h1>'
            . '<p style="margin:0 0 24px;line-height:1.6;color:#c5d0e6;">' . htmlspecialchars($intro, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<p style="margin:0 0 28px;"><a href="' . $safeUrl . '" style="display:inline-block;background:#2ee6c6;color:#06201b;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:999px;">'
            . htmlspecialchars($cta, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></p>'
            . '<p style="margin:0 0 12px;font-size:13px;color:#8ea0bd;">' . htmlspecialchars($hint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<p style="margin:0;font-size:12px;color:#6b7c96;word-break:break-all;">Si le bouton ne fonctionne pas : ' . $safeUrl . '</p>'
            . '</div></body></html>';
    }

    private static function textTemplate(string $name, string $intro, string $url, string $hint): string
    {
        return "Bonjour {$name},\n\n{$intro}\n\n{$url}\n\n{$hint}\n\n— Nexus\n";
    }
}
