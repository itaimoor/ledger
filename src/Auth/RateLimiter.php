<?php

declare(strict_types=1);

namespace Ledger\Auth;

use Ledger\Exceptions\HttpException;
use PDO;

/**
 * Fixed-window request counter, backed by the rate_limits table.
 *
 * ponytail: fixed window, not sliding. A caller can therefore send up to 2× the limit
 * across a window boundary. That is an acceptable ceiling for slowing down credential
 * stuffing; move to a sliding window or token bucket if real abuse shows up.
 */
final class RateLimiter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $windowSeconds,
    ) {
    }

    /** Email addresses are hashed so the counter table never holds an address in clear. */
    public static function loginByEmail(string $email): string
    {
        return 'login:email:' . hash('sha256', mb_strtolower($email));
    }

    public static function loginByIp(string $ip): string
    {
        return 'login:ip:' . $ip;
    }

    public static function writesByUser(int $userId): string
    {
        return 'write:user:' . $userId;
    }

    /** Signup is public, so the only thing to meter it against is the origin address. */
    public static function registrationsByIp(string $ip): string
    {
        return 'register:ip:' . $ip;
    }

    /** @throws HttpException 429 once the bucket exceeds $max within the window */
    public function hit(string $bucket, int $max): void
    {
        $now = time();
        $windowStart = $now - ($now % $this->windowSeconds);

        $this->pdo
            ->prepare(
                'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE hits = hits + 1'
            )
            ->execute([$bucket, $windowStart]);

        $count = $this->pdo->prepare('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?');
        $count->execute([$bucket, $windowStart]);

        if ((int) $count->fetchColumn() > $max) {
            throw HttpException::tooManyRequests($windowStart + $this->windowSeconds - $now);
        }

        $this->pruneOccasionally($now);
    }

    /**
     * Expired windows are dead weight. Sweeping them on roughly one call in a hundred
     * keeps the table small without needing a cron entry.
     */
    private function pruneOccasionally(int $now): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }

        $this->pdo
            ->prepare('DELETE FROM rate_limits WHERE window_start < ?')
            ->execute([$now - ($this->windowSeconds * 2)]);
    }
}
