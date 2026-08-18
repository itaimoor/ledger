<?php

declare(strict_types=1);

namespace Ledger\Services;

use Ledger\Auth\AuthenticatedUser;
use Ledger\Domain\Role;
use Ledger\Repositories\ActivityLogRepository;
use Ledger\Repositories\CategoryRepository;
use Ledger\Repositories\MembershipRepository;
use Ledger\Repositories\OrganizationRepository;
use Ledger\Security\Action;
use Ledger\Security\Membership;
use Ledger\Security\Policy;
use Ledger\Support\Slug;
use PDO;
use Throwable;

final class OrganizationService
{
    /** The list the design promises on the create-organization screen. */
    private const DEFAULT_CATEGORIES = [
        ['Labour', 'out'],
        ['Material', 'out'],
        ['Transport', 'out'],
        ['Fuel', 'out'],
        ['Rent', 'out'],
        ['Misc', 'out'],
        ['Client payment', 'in'],
    ];

    public function __construct(
        private readonly OrganizationRepository $organizations,
        private readonly MembershipRepository $memberships,
        private readonly CategoryRepository $categories,
        private readonly ActivityLogRepository $activity,
        private readonly Policy $policy,
        private readonly PDO $pdo,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listForUser(int $userId): array
    {
        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'role' => (string) $row['role'],
                'joined_at' => (string) $row['joined_at'],
            ],
            $this->memberships->listForUser($userId),
        );
    }

    /** @return array<string, mixed> */
    public function create(AuthenticatedUser $user, string $name, string $ip): array
    {
        $this->pdo->beginTransaction();

        try {
            $organization = $this->createWithinTransaction($user->id, $name, $ip);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return $organization;
    }

    /**
     * The body of create(), for callers that already hold a transaction — registration
     * creates the account and the organization together or not at all, and PDO has no
     * nested transactions to lean on.
     *
     * @return array<string, mixed>
     */
    public function createWithinTransaction(int $userId, string $name, string $ip): array
    {
        $slug = Slug::unique(
            Slug::from($name),
            fn (string $candidate): bool => $this->organizations->slugExists($candidate),
        );

        $orgId = $this->organizations->create($name, $slug, $userId);
        $this->memberships->add($orgId, $userId, Role::Owner->value);
        $this->categories->createMany($orgId, self::DEFAULT_CATEGORIES);

        $this->activity->record(
            $orgId,
            $userId,
            'organization.created',
            'organization',
            $orgId,
            after: ['name' => $name, 'slug' => $slug],
            ip: $ip,
        );

        return ['id' => $orgId, 'name' => $name, 'slug' => $slug, 'role' => Role::Owner->value];
    }

    /** @return array<string, mixed> */
    public function show(Membership $membership): array
    {
        $this->policy->authorize($membership, Action::ViewOrganization);

        /** @var array<string, mixed> $organization */
        $organization = $this->organizations->find($membership->orgId);

        return [
            'id' => (int) $organization['id'],
            'name' => (string) $organization['name'],
            'slug' => (string) $organization['slug'],
            'created_at' => (string) $organization['created_at'],
            'your_role' => $membership->role->value,
        ];
    }

    public function rename(Membership $membership, string $name, string $ip): void
    {
        $this->policy->authorize($membership, Action::ManageOrganization, ip: $ip);

        $before = $this->organizations->find($membership->orgId);
        $this->organizations->rename($membership->orgId, $name);

        $this->activity->record(
            $membership->orgId,
            $membership->userId,
            'organization.renamed',
            'organization',
            $membership->orgId,
            before: ['name' => $before['name'] ?? null],
            after: ['name' => $name],
            ip: $ip,
        );
    }

    public function delete(Membership $membership, string $ip): void
    {
        $this->policy->authorize($membership, Action::ManageOrganization, ip: $ip);

        $this->organizations->softDelete($membership->orgId);

        $this->activity->record(
            $membership->orgId,
            $membership->userId,
            'organization.deleted',
            'organization',
            $membership->orgId,
            ip: $ip,
        );
    }
}
