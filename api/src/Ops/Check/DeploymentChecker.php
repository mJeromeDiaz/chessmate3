<?php

declare(strict_types=1);

namespace App\Ops\Check;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Is this host ready to run the application (docs/DEPLOY_OVH.md)? Checked from the command line
 * (`app:deploy:check`, over SSH) and from the web (`GET /api/ops/check`): on a shared host, PHP
 * may differ between the two (version, extensions, outgoing connections).
 *
 * An `error` breaks the application; a `warning` is a production setting to review.
 *
 * @phpstan-type Check array{name: string, level: 'ok'|'warning'|'error', detail: string}
 */
final readonly class DeploymentChecker
{
    private const EXTENSIONS = ['sodium', 'pdo_mysql', 'ctype', 'iconv', 'zip', 'openssl', 'mbstring'];

    public function __construct(
        private Connection $connection,
        private HttpClientInterface $httpClient,
        #[Autowire('%kernel.environment%')]
        private string $environment,
        #[Autowire('%kernel.cache_dir%')]
        private string $cacheDir,
        #[Autowire('%kernel.logs_dir%')]
        private string $logsDir,
        #[Autowire('%env(APP_SECRET)%')]
        private string $appSecret,
        #[Autowire('%env(MAILER_DSN)%')]
        private string $mailerDsn,
        #[Autowire('%env(FRONTEND_URL)%')]
        private string $frontendUrl,
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $defaultUri,
        #[Autowire('%env(bool:REFRESH_COOKIE_SECURE)%')]
        private bool $secureCookies,
        #[Autowire('%env(OAUTH_TOKEN_ENCRYPTION_KEY)%')]
        private string $tokenKey,
        #[Autowire('%env(CALENDAR_TOKEN_KEY)%')]
        private string $calendarKey,
        #[Autowire('%env(VAPID_PRIVATE_KEY)%')]
        private string $vapidKey,
        #[Autowire('%env(OPS_TICK_TOKEN)%')]
        private string $tickToken,
        #[Autowire('%env(bool:OPS_DRAIN_ON_TERMINATE)%')]
        private bool $drainOnTerminate,
        #[Autowire('%env(resolve:JWT_SECRET_KEY)%')]
        private string $jwtSecretKey,
        #[Autowire('%env(resolve:JWT_PUBLIC_KEY)%')]
        private string $jwtPublicKey,
    ) {
    }

    /**
     * @param bool $network also try an outgoing HTTPS connection (Google's OpenID configuration)
     *
     * @return list<Check>
     */
    public function run(bool $network = false): array
    {
        $checks = [
            self::check('PHP', version_compare(\PHP_VERSION, '8.2.0', '>=') ? 'ok' : 'error', \PHP_VERSION.' ('.\PHP_SAPI.')'),
        ];
        foreach (self::EXTENSIONS as $extension) {
            $checks[] = self::check('ext-'.$extension, \extension_loaded($extension) ? 'ok' : 'error', \extension_loaded($extension) ? 'loaded' : 'missing');
        }
        $checks[] = self::check('Argon2id', \defined('PASSWORD_ARGON2ID') ? 'ok' : 'warning', \defined('PASSWORD_ARGON2ID') ? 'available' : 'missing: passwords fall back to bcrypt');
        $memory = self::bytes((string) \ini_get('memory_limit'));
        $checks[] = self::check('memory_limit', -1 === $memory || $memory >= 256 * 1024 * 1024 ? 'ok' : 'warning', (string) \ini_get('memory_limit'));
        $checks[] = self::check('fastcgi_finish_request', \function_exists('fastcgi_finish_request') || 'cli' === \PHP_SAPI ? 'ok' : 'warning', \function_exists('fastcgi_finish_request') ? 'available' : ('cli' === \PHP_SAPI ? 'n/a (cli)' : 'missing: the drain after a request delays the response'));

        $checks = [...$checks, ...$this->database()];

        foreach (['cache' => $this->cacheDir, 'logs' => $this->logsDir] as $name => $dir) {
            $checks[] = self::check('var/'.$name, is_dir($dir) && is_writable($dir) ? 'ok' : 'error', $dir);
        }
        foreach (['JWT private key' => $this->jwtSecretKey, 'JWT public key' => $this->jwtPublicKey] as $name => $path) {
            $checks[] = self::check($name, is_readable($path) ? 'ok' : 'error', is_readable($path) ? 'readable' : 'unreadable: '.$path);
        }
        foreach (['APP_SECRET' => $this->appSecret, 'OAUTH_TOKEN_ENCRYPTION_KEY' => $this->tokenKey, 'CALENDAR_TOKEN_KEY' => $this->calendarKey] as $name => $value) {
            $checks[] = self::check($name, '' !== $value ? 'ok' : 'error', '' !== $value ? 'set' : 'empty');
        }
        // Optional: without it, no browser notification (emails still go).
        $checks[] = self::check('VAPID_PRIVATE_KEY', '' !== $this->vapidKey ? 'ok' : 'warning', '' !== $this->vapidKey ? 'set' : 'empty: no Web Push');

        $prod = 'prod' === $this->environment;
        $checks[] = self::check('APP_ENV', $prod ? 'ok' : 'warning', $this->environment);
        $scheme = strtolower((string) strtok($this->mailerDsn, ':'));
        $checks[] = self::check('MAILER_DSN', 'null' === $scheme ? 'warning' : 'ok', $scheme.'://… (mailfunction on the shared host)');
        $checks[] = self::check('FRONTEND_URL', str_starts_with($this->frontendUrl, 'https://') ? 'ok' : 'warning', $this->frontendUrl);
        $checks[] = self::check('DEFAULT_URI', str_starts_with($this->defaultUri, 'https://') ? 'ok' : 'warning', $this->defaultUri);
        $checks[] = self::check('REFRESH_COOKIE_SECURE', $this->secureCookies ? 'ok' : 'warning', $this->secureCookies ? 'on' : 'off');
        $checks[] = self::check('OPS_TICK_TOKEN', \strlen($this->tickToken) >= 32 ? 'ok' : 'warning', \strlen($this->tickToken) >= 32 ? 'set' : 'not set: no tick, no queue consumed without a worker');
        $checks[] = self::check('OPS_DRAIN_ON_TERMINATE', 'ok', $this->drainOnTerminate ? 'on' : 'off (on for the shared host)');

        if ($network) {
            $checks[] = $this->outgoing();
        }

        return $checks;
    }

    /**
     * @param list<Check> $checks
     */
    public static function hasErrors(array $checks): bool
    {
        foreach ($checks as $check) {
            if ('error' === $check['level']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Check>
     */
    private function database(): array
    {
        try {
            $version = $this->connection->fetchOne('SELECT VERSION()');
            $version = \is_string($version) ? $version : '';
        } catch (\Throwable $exception) {
            return [self::check('MySQL', 'error', 'unreachable: '.$exception->getMessage())];
        }
        $checks = [self::check('MySQL', version_compare($version, '8.0.0', '>=') ? 'ok' : 'error', $version)];
        $tables = $this->connection->createSchemaManager()->listTableNames();
        $checks[] = self::check('Messenger table', \in_array('messenger_messages', $tables, true) ? 'ok' : 'error', \in_array('messenger_messages', $tables, true) ? 'messenger_messages' : 'missing: run the migrations');

        return $checks;
    }

    /**
     * @return Check
     */
    private function outgoing(): array
    {
        try {
            $status = $this->httpClient->request('GET', 'https://accounts.google.com/.well-known/openid-configuration', ['timeout' => 5])->getStatusCode();

            return self::check('Outgoing HTTPS', 200 === $status ? 'ok' : 'warning', 'accounts.google.com: '.$status);
        } catch (\Throwable $exception) {
            return self::check('Outgoing HTTPS', 'error', 'blocked: '.$exception->getMessage());
        }
    }

    /**
     * @param 'ok'|'warning'|'error' $level
     *
     * @return Check
     */
    private static function check(string $name, string $level, string $detail): array
    {
        return ['name' => $name, 'level' => $level, 'detail' => $detail];
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ('-1' === $value) {
            return -1;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
