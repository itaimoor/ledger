<?php

declare(strict_types=1);

namespace Ledger\Services;

use Ledger\Auth\AuthenticatedUser;
use Ledger\Auth\RateLimiter;
use Ledger\Auth\TokenService;
use Ledger\Exceptions\HttpException;
use Ledger\Exceptions\ValidationException;
use Ledger\Repositories\ActivityLogRepository;
use Ledger\Repositories\UserRepository;
use PDO;
use Throwable;

final class AuthService
{
    /**
     * A real Argon2id digest of a random string, verified against whenever the email is
     * unknown. Without it, a miss returns far faster than a wrong password and the login
     * endpoint becomes an account-enumeration oracle.
     */
    private const TIMING_DECOY =
        '$argon2id$v=19$m=65536,t=4,p=1$dDNhL0YwLmZtdjhXeUpmcg$6xIOOux7+rkoyflkUmBeerxsn8y+ireYPUTQLNcRRWI';

    public const MINIMUM_PASSWORD_LENGTH = 12;

    public function __construct(
        private readonly UserRepository $users,
        private readonly TokenService $tokens,
        private readonly RateLimiter $limiter,
        private readonly ActivityLogRepository $activity,
        private readonly OrganizationService $organizations,
        private readonly PDO $pdo,
        private readonly int $loginAttemptsPerIp,
        private readonly int $loginAttemptsPerEmail,
        private readonly int $registrationsPerIp,
    ) {
    }

    /**
     * Self-service signup: the account and its first organization are created together,
     * and the caller is signed in immediately.
     *
     * A taken address is reported plainly. That is an enumeration vector, but the
     * alternative — accepting the registration and silently doing nothing — leaves the
     * person staring at a screen that lied to them. Login stays generic; this endpoint is
     * rate limited per IP instead.
     *
     * @return array<string, mixed>
     */
    public function register(
        string $name,
        string $email,
        string $password,
        string $organizationName,
        string $ip,
        ?string $userAgent,
    ): array {
        $this->limiter->hit(RateLimiter::registrationsByIp($ip), $this->registrationsPerIp);

        if ($this->users->findByEmail($email) !== null) {
            throw new ValidationException(
                ['email' => 'An account already exists for this address. Sign in instead.'],
            );
        }

        $this->pdo->beginTransaction();

        try {
            $userId = $this->users->create($name, $email, $password);
            $organization = $this->organizations->createWithinTransaction($userId, $organizationName, $ip);

            $this->activity->record($organization['id'], $userId, 'auth.registered', 'user', $userId, ip: $ip);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return $this->tokens->issuePair($userId, null, $ip, $userAgent)
            + ['must_change_password' => false, 'organization' => $organization];
    }

    /** @return array<string, mixed> */
    public function login(string $email, string $password, string $ip, ?string $userAgent): array
    {
        // Both buckets, so one address cannot be ground down from many hosts and one host
        // cannot walk a list of addresses.
        $this->limiter->hit(RateLimiter::loginByIp($ip), $this->loginAttemptsPerIp);
        $this->limiter->hit(RateLimiter::loginByEmail($email), $this->loginAttemptsPerEmail);

        $user = $this->users->findByEmail($email);

        if ($user === null) {
            password_verify($password, self::TIMING_DECOY);
            $this->activity->record(null, null, 'auth.login_failed', ip: $ip);

            throw HttpException::unauthorized();
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->activity->record(null, (int) $user['id'], 'auth.login_failed', ip: $ip);

            throw HttpException::unauthorized();
        }

        if ($user['status'] !== 'active') {
            $this->activity->record(null, (int) $user['id'], 'auth.login_suspended', ip: $ip);

            throw HttpException::unauthorized();
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_ARGON2ID)) {
            $this->users->updatePasswordHash(
                (int) $user['id'],
                password_hash($password, PASSWORD_ARGON2ID),
                (bool) $user['must_change_password'],
            );
        }

        $this->users->touchLastSeen((int) $user['id']);
        $this->activity->record(null, (int) $user['id'], 'auth.login', ip: $ip);

        return $this->tokens->issuePair((int) $user['id'], null, $ip, $userAgent)
            + ['must_change_password' => (bool) $user['must_change_password']];
    }

    /** @return array<string, mixed> */
    public function refresh(string $refreshToken, string $ip, ?string $userAgent): array
    {
        $this->limiter->hit(RateLimiter::loginByIp($ip), $this->loginAttemptsPerIp);

        return $this->tokens->rotate($refreshToken, $ip, $userAgent);
    }

    public function logout(string $refreshToken): void
    {
        $this->tokens->revokeFamilyOf($refreshToken);
    }

    /**
     * Self-service rename. The email is the sign-in identity and stays fixed in this
     * phase; the name is just how the person appears in the book.
     *
     * @return array{id: int, name: string, email: string, must_change_password: bool}
     */
    public function updateName(AuthenticatedUser $caller, string $name, string $ip): array
    {
        $this->users->updateName($caller->id, $name);

        $this->activity->record(
            null,
            $caller->id,
            'user.renamed',
            'user',
            $caller->id,
            before: ['name' => $caller->name],
            after: ['name' => $name],
            ip: $ip,
        );

        return [
            'id' => $caller->id,
            'name' => $name,
            'email' => $caller->email,
            'must_change_password' => $caller->mustChangePassword,
        ];
    }

    /**
     * Needed because an admin-provisioned account starts on a one-time password. Every
     * other session in the family is revoked, so a stolen password cannot outlive the
     * change.
     */
    public function changePassword(
        AuthenticatedUser $caller,
        string $currentPassword,
        string $newPassword,
        string $ip,
    ): void {
        $user = $this->users->findByEmail($caller->email);

        if ($user === null || !password_verify($currentPassword, (string) $user['password_hash'])) {
            $this->activity->record(null, $caller->id, 'auth.password_change_failed', ip: $ip);

            throw new ValidationException(['current_password' => 'That is not your current password.']);
        }

        if (password_verify($newPassword, (string) $user['password_hash'])) {
            throw new ValidationException(['new_password' => 'Choose a password you have not used here before.']);
        }

        $this->users->updatePasswordHash(
            $caller->id,
            password_hash($newPassword, PASSWORD_ARGON2ID),
            false,
        );

        $this->activity->record(null, $caller->id, 'auth.password_changed', 'user', $caller->id, ip: $ip);
    }
}
