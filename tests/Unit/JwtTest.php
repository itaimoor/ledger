<?php

declare(strict_types=1);

namespace Ledger\Tests\Unit;

use Ledger\Auth\Jwt;
use Ledger\Exceptions\HttpException;
use PHPUnit\Framework\TestCase;

final class JwtTest extends TestCase
{
    private const SECRET = 'MJ1qTQ0bYQ1BDkPfaWmnvyRs0mNTUXGvIRFYfLcCXsE=';

    private function jwt(string $issuer = 'ledger'): Jwt
    {
        return new Jwt(self::SECRET, $issuer);
    }

    public function testRoundTripsTheUserId(): void
    {
        $token = $this->jwt()->issue(42, 900);

        self::assertSame(42, $this->jwt()->verify($token));
    }

    public function testRejectsAnExpiredToken(): void
    {
        $token = $this->jwt()->issue(42, 900, now: 1_000_000);

        $this->expectException(HttpException::class);

        $this->jwt()->verify($token, now: 1_000_901);
    }

    public function testAcceptsATokenOneSecondBeforeExpiry(): void
    {
        $token = $this->jwt()->issue(42, 900, now: 1_000_000);

        self::assertSame(42, $this->jwt()->verify($token, now: 1_000_899));
    }

    public function testRejectsATokenSignedWithADifferentSecret(): void
    {
        $token = (new Jwt('a-completely-different-secret', 'ledger'))->issue(42, 900);

        $this->expectException(HttpException::class);

        $this->jwt()->verify($token);
    }

    public function testRejectsATokenFromAnotherIssuer(): void
    {
        $token = $this->jwt('somebody-else')->issue(42, 900);

        $this->expectException(HttpException::class);

        $this->jwt()->verify($token);
    }

    /** The classic forgery: strip the signature and claim the token needs none. */
    public function testRejectsTheAlgNoneForgery(): void
    {
        $header = $this->base64Url('{"alg":"none","typ":"JWT"}');
        $claims = $this->base64Url((string) json_encode([
            'iss' => 'ledger',
            'sub' => '1',
            'iat' => time(),
            'exp' => time() + 900,
        ]));

        $this->expectException(HttpException::class);

        $this->jwt()->verify("{$header}.{$claims}.");
    }

    /** A token whose header claims HS512 must not be accepted just because HMAC matches. */
    public function testRejectsAnAlgorithmSubstitution(): void
    {
        $header = $this->base64Url('{"alg":"HS512","typ":"JWT"}');
        $claims = $this->base64Url((string) json_encode([
            'iss' => 'ledger',
            'sub' => '1',
            'iat' => time(),
            'exp' => time() + 900,
        ]));
        $signature = $this->base64Url(hash_hmac('sha512', "{$header}.{$claims}", self::SECRET, true));

        $this->expectException(HttpException::class);

        $this->jwt()->verify("{$header}.{$claims}.{$signature}");
    }

    public function testRejectsATamperedPayload(): void
    {
        [$header, , $signature] = explode('.', $this->jwt()->issue(42, 900));

        $forged = $this->base64Url((string) json_encode([
            'iss' => 'ledger',
            'sub' => '1',
            'iat' => time(),
            'exp' => time() + 900,
        ]));

        $this->expectException(HttpException::class);

        $this->jwt()->verify("{$header}.{$forged}.{$signature}");
    }

    public function testEveryRejectionUsesTheSameGenericMessage(): void
    {
        $messages = [];

        foreach (['', 'nonsense', 'a.b.c', $this->jwt('other')->issue(1, 900)] as $token) {
            try {
                $this->jwt()->verify($token);
            } catch (HttpException $e) {
                $messages[] = $e->getMessage() . '/' . $e->status();
            }
        }

        self::assertCount(4, $messages);
        self::assertCount(1, array_unique($messages));
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
