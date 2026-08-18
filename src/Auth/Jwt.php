<?php

declare(strict_types=1);

namespace Ledger\Auth;

use JsonException;
use Ledger\Exceptions\HttpException;

/**
 * Minimal HS256 JWT, signed with hash_hmac.
 *
 * The algorithm is fixed at HS256 and checked on the way in, which closes the two classic
 * JWT holes in one line: a token claiming `alg: none`, and a token claiming an asymmetric
 * algorithm so the public key gets used as an HMAC secret.
 */
final class Jwt
{
    private const ALGORITHM = 'HS256';

    public function __construct(
        private readonly string $secret,
        private readonly string $issuer,
    ) {
    }

    public function issue(int $userId, int $ttlSeconds, ?int $now = null): string
    {
        $now ??= time();

        $signingInput = $this->encodeSegment(['alg' => self::ALGORITHM, 'typ' => 'JWT'])
            . '.'
            . $this->encodeSegment([
                'iss' => $this->issuer,
                'sub' => (string) $userId,
                'iat' => $now,
                'exp' => $now + $ttlSeconds,
            ]);

        return $signingInput . '.' . $this->sign($signingInput);
    }

    /**
     * @return int the authenticated user id
     *
     * @throws HttpException on any failure, always with the same message — the client
     *                       must not learn whether a token was malformed, forged, or
     *                       merely expired
     */
    public function verify(string $token, ?int $now = null): int
    {
        $now ??= time();
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw HttpException::unauthorized();
        }

        [$header64, $claims64, $signature64] = $parts;

        if (!hash_equals($this->sign($header64 . '.' . $claims64), $signature64)) {
            throw HttpException::unauthorized();
        }

        $header = $this->decodeSegment($header64);
        $claims = $this->decodeSegment($claims64);

        $valid = ($header['alg'] ?? null) === self::ALGORITHM
            && ($header['typ'] ?? null) === 'JWT'
            && ($claims['iss'] ?? null) === $this->issuer
            && is_int($claims['exp'] ?? null)
            && $claims['exp'] > $now
            && is_string($claims['sub'] ?? null)
            && ctype_digit($claims['sub']);

        if (!$valid) {
            throw HttpException::unauthorized();
        }

        return (int) $claims['sub'];
    }

    private function sign(string $signingInput): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $signingInput, $this->secret, true));
    }

    /** @param array<string, mixed> $segment */
    private function encodeSegment(array $segment): string
    {
        return $this->base64UrlEncode(json_encode($segment, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function decodeSegment(string $segment): array
    {
        $json = base64_decode(strtr($segment, '-_', '+/'), true);

        if ($json === false) {
            throw HttpException::unauthorized();
        }

        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw HttpException::unauthorized();
        }

        return is_array($decoded) ? $decoded : throw HttpException::unauthorized();
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
