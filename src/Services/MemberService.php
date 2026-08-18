<?php

declare(strict_types=1);

namespace Ledger\Services;

use Ledger\Domain\Role;
use Ledger\Exceptions\HttpException;
use Ledger\Exceptions\ValidationException;
use Ledger\Repositories\ActivityLogRepository;
use Ledger\Repositories\InviteRepository;
use Ledger\Repositories\MembershipRepository;
use Ledger\Repositories\RefreshTokenRepository;
use Ledger\Repositories\UserRepository;
use Ledger\Security\Action;
use Ledger\Security\Membership;
use Ledger\Security\Policy;
use PDO;
use Throwable;

/**
 * Member provisioning without email.
 *
 * Two routes in, both ending with the admin holding a secret they pass on by hand:
 *
 *   create()  makes the account immediately and returns a one-time password, shown once.
 *   invite()  returns a single-use link; the recipient sets their own password.
 *
 * Only digests are stored, so neither secret can be recovered from the database.
 */
final class MemberService
{
    /**
     * No I, L, O, 0 or 1. Somebody is going to read this down a phone line or copy it off
     * a screen, and a password you cannot dictate unambiguously is a support call.
     */
    private const UNAMBIGUOUS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly UserRepository $users,
        private readonly InviteRepository $invites,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly ActivityLogRepository $activity,
        private readonly Policy $policy,
        private readonly PDO $pdo,
        private readonly string $appUrl,
        private readonly int $inviteTtlHours,
    ) {
    }

    /** Three groups of four, e.g. "K7QM-3XPT-9RFH". */
    public static function generateOneTimePassword(): string
    {
        $groups = [];

        for ($group = 0; $group < 3; ++$group) {
            $chunk = '';

            for ($character = 0; $character < 4; ++$character) {
                $chunk .= self::UNAMBIGUOUS[random_int(0, strlen(self::UNAMBIGUOUS) - 1)];
            }

            $groups[] = $chunk;
        }

        return implode('-', $groups);
    }

    /** @return array{members: list<array<string, mixed>>, pending_invites: list<array<string, mixed>>} */
    public function list(Membership $membership): array
    {
        $this->policy->authorize($membership, Action::ViewOrganization);

        $members = array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'email' => (string) $row['email'],
                'role' => (string) $row['role'],
                'status' => (string) $row['status'],
                'joined_at' => (string) $row['joined_at'],
                'last_seen_at' => $row['last_seen_at'] === null ? null : (string) $row['last_seen_at'],
            ],
            $this->memberships->listForOrg($membership->orgId),
        );

        // Pending invites belong on the same screen, so they travel in the same response.
        $pending = $this->canManage($membership)
            ? array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'email' => (string) $row['email'],
                    'role' => (string) $row['role'],
                    'invited_by' => (string) $row['invited_by_name'],
                    'created_at' => (string) $row['created_at'],
                    'expires_at' => (string) $row['expires_at'],
                ],
                $this->invites->listPending($membership->orgId),
            )
            : [];

        return ['members' => $members, 'pending_invites' => $pending];
    }

    /**
     * Creates the account outright and hands back the password exactly once. The admin may
     * type the password instead of taking the generated one; either way they know it, so
     * the account stays flagged until its holder replaces it.
     *
     * @return array<string, mixed>
     */
    public function create(
        Membership $membership,
        string $name,
        string $email,
        string $role,
        string $ip,
        ?string $password = null,
    ): array {
        $this->policy->authorize($membership, Action::ManageMembers, Role::from($role), ip: $ip);

        $existing = $this->users->findByEmail($email);

        if ($existing !== null && $this->memberships->find($membership->orgId, (int) $existing['id']) !== null) {
            throw HttpException::conflict('That person is already a member of this organization.');
        }

        if ($existing !== null && $password !== null) {
            throw new ValidationException(
                ['password' => 'They already have an account and keep their password. Use Reset password instead.'],
            );
        }

        $oneTimePassword = $existing === null && $password === null ? self::generateOneTimePassword() : null;

        $this->pdo->beginTransaction();

        try {
            $userId = $existing !== null
                ? (int) $existing['id']
                : $this->users->createWithTemporaryPassword($name, $email, $password ?? (string) $oneTimePassword);

            $this->memberships->add($membership->orgId, $userId, $role);
            $this->invites->revokePendingFor($membership->orgId, $email);

            $this->activity->record(
                $membership->orgId,
                $membership->userId,
                'member.added',
                'user',
                $userId,
                after: [
                    'email' => $email,
                    'role' => $role,
                    'account_created' => $existing === null,
                    'password_set_by_admin' => $password !== null,
                ],
                ip: $ip,
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return [
            'user' => ['id' => $userId, 'name' => $name, 'email' => $email, 'role' => $role],
            'account_created' => $existing === null,
            // Null when the person already had an account, or when the admin typed one.
            'one_time_password' => $oneTimePassword,
        ];
    }

    /**
     * Returns a link the admin copies and delivers however they like. Only the digest is
     * stored, so the link cannot be recovered — a lost one is reissued, not looked up.
     *
     * @return array<string, mixed>
     */
    public function invite(Membership $membership, string $email, string $role, string $ip): array
    {
        $this->policy->authorize($membership, Action::ManageMembers, Role::from($role), ip: $ip);

        $existing = $this->users->findByEmail($email);

        if ($existing !== null && $this->memberships->find($membership->orgId, (int) $existing['id']) !== null) {
            throw HttpException::conflict('That person is already a member of this organization.');
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + ($this->inviteTtlHours * 3600));

        $this->pdo->beginTransaction();

        try {
            $this->invites->revokePendingFor($membership->orgId, $email);

            $inviteId = $this->invites->create(
                $membership->orgId,
                $email,
                $role,
                hash('sha256', $token),
                $membership->userId,
                $expiresAt,
            );

            $this->activity->record(
                $membership->orgId,
                $membership->userId,
                'invite.created',
                'invite',
                $inviteId,
                after: ['email' => $email, 'role' => $role],
                ip: $ip,
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return [
            'id' => $inviteId,
            'email' => $email,
            'role' => $role,
            'expires_at' => $expiresAt,
            'signup_url' => rtrim($this->appUrl, '/') . '/join/' . $token,
        ];
    }

    public function revokeInvite(Membership $membership, int $inviteId, string $ip): void
    {
        $this->policy->authorize($membership, Action::ManageMembers, ip: $ip);

        $this->invites->revoke($membership->orgId, $inviteId);

        $this->activity->record(
            $membership->orgId,
            $membership->userId,
            'invite.revoked',
            'invite',
            $inviteId,
            ip: $ip,
        );
    }

    /**
     * What the recipient sees before committing: which organization, which role, who
     * invited them. Public — the token is the only credential they have.
     *
     * @return array<string, mixed>
     */
    public function previewInvite(string $token): array
    {
        $invite = $this->validInviteOrFail($token);

        return [
            'organization' => ['name' => (string) $invite['organization_name']],
            'email' => (string) $invite['email'],
            'role' => (string) $invite['role'],
            'invited_by' => (string) $invite['invited_by_name'],
            'expires_at' => (string) $invite['expires_at'],
            'account_exists' => $this->users->findByEmail((string) $invite['email']) !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function acceptInvite(string $token, ?string $name, ?string $password, string $ip): array
    {
        $invite = $this->validInviteOrFail($token);
        $email = (string) $invite['email'];
        $orgId = (int) $invite['org_id'];
        $existing = $this->users->findByEmail($email);

        // An account already on this address keeps its own password. Granting membership
        // to an account the holder of the link cannot sign into gives them nothing.
        if ($existing === null && ($name === null || $password === null)) {
            $missing = [];

            if ($name === null) {
                $missing['name'] = 'This field is required.';
            }

            if ($password === null) {
                $missing['password'] = 'This field is required.';
            }

            throw new ValidationException($missing, 'Set a name and password to create your account.');
        }

        $this->pdo->beginTransaction();

        try {
            $userId = $existing !== null
                ? (int) $existing['id']
                : $this->users->create((string) $name, $email, (string) $password);

            if ($this->memberships->find($orgId, $userId) === null) {
                $this->memberships->add($orgId, $userId, (string) $invite['role']);
            }

            $this->invites->markAccepted((int) $invite['id'], $userId);

            $this->activity->record(
                $orgId,
                $userId,
                'invite.accepted',
                'invite',
                (int) $invite['id'],
                after: ['email' => $email, 'role' => $invite['role']],
                ip: $ip,
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return [
            'organization' => ['id' => $orgId, 'name' => (string) $invite['organization_name']],
            'role' => (string) $invite['role'],
            'account_created' => $existing === null,
        ];
    }

    public function declineInvite(string $token, string $ip): void
    {
        $invite = $this->validInviteOrFail($token);

        $this->invites->revokeById((int) $invite['id']);
        $this->activity->record(
            (int) $invite['org_id'],
            null,
            'invite.declined',
            'invite',
            (int) $invite['id'],
            ip: $ip,
        );
    }

    public function changeRole(Membership $membership, int $targetUserId, string $role, string $ip): void
    {
        $target = $this->requireMember($membership, $targetUserId);

        // Checked against the target's current role: nobody demotes an owner from here.
        $this->policy->authorize($membership, Action::ManageMembers, $target->role, ip: $ip);
        $this->policy->authorize($membership, Action::ManageMembers, Role::from($role), ip: $ip);

        $this->memberships->updateRole($membership->orgId, $targetUserId, $role);

        $this->activity->record(
            $membership->orgId,
            $membership->userId,
            'member.role_changed',
            'user',
            $targetUserId,
            before: ['role' => $target->role->value],
            after: ['role' => $role],
            ip: $ip,
        );
    }

    public function remove(Membership $membership, int $targetUserId, string $ip): void
    {
        $target = $this->requireMember($membership, $targetUserId);

        $this->policy->authorize($membership, Action::ManageMembers, $target->role, ip: $ip);

        $this->memberships->remove($membership->orgId, $targetUserId);

        $this->activity->record(
            $membership->orgId,
            $membership->userId,
            'member.removed',
            'user',
            $targetUserId,
            before: ['role' => $target->role->value],
            ip: $ip,
        );
    }

    /**
     * An admin replaces a member's password — with one they typed, or with a generated
     * one-time password returned exactly once. Either way the target must choose their own
     * on next sign-in, and every session they had is revoked: a reset that left the old
     * sessions alive would not be a reset. (An access token already issued survives its
     * remaining lifetime, at most 15 minutes — the documented bound.)
     *
     * @return array{one_time_password: ?string}
     */
    public function resetPassword(Membership $membership, int $targetUserId, ?string $password, string $ip): array
    {
        $target = $this->requireMember($membership, $targetUserId);

        $this->policy->authorize($membership, Action::ResetMemberPassword, $target->role, ip: $ip);

        $oneTimePassword = $password === null ? self::generateOneTimePassword() : null;

        $this->pdo->beginTransaction();

        try {
            $this->users->updatePasswordHash(
                $targetUserId,
                password_hash($password ?? (string) $oneTimePassword, PASSWORD_ARGON2ID),
                mustChange: true,
            );

            $revoked = $this->refreshTokens->revokeAllForUser($targetUserId);

            $this->activity->record(
                $membership->orgId,
                $membership->userId,
                'member.password_reset',
                'user',
                $targetUserId,
                after: ['generated' => $password === null, 'sessions_revoked' => $revoked],
                ip: $ip,
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return ['one_time_password' => $oneTimePassword];
    }

    private function requireMember(Membership $membership, int $targetUserId): Membership
    {
        return $this->memberships->find($membership->orgId, $targetUserId)
            ?? throw HttpException::notFound('No such member.');
    }

    private function canManage(Membership $membership): bool
    {
        return $this->policy->can($membership, Action::ManageMembers);
    }

    /**
     * Expired, revoked, already accepted, unknown, or belonging to a deleted organization
     * all produce the same 404. A link that no longer works should not explain why.
     *
     * @return array<string, mixed>
     */
    private function validInviteOrFail(string $token): array
    {
        $invite = $this->invites->findByTokenHash(hash('sha256', $token));

        $usable = $invite !== null
            && $invite['accepted_at'] === null
            && $invite['revoked_at'] === null
            && $invite['organization_deleted_at'] === null
            && strtotime((string) $invite['expires_at']) > time();

        return $usable
            ? $invite
            : throw HttpException::notFound('That invitation is no longer valid.');
    }
}
