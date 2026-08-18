<?php

declare(strict_types=1);

namespace Ledger\Services;

use Ledger\Exceptions\HttpException;
use Ledger\Repositories\ActivityLogRepository;
use Ledger\Repositories\CategoryRepository;
use Ledger\Security\Action;
use Ledger\Security\Membership;
use Ledger\Security\Policy;

final class CategoryService
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly ActivityLogRepository $activity,
        private readonly Policy $policy,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(Membership $membership, bool $includeArchived): array
    {
        $this->policy->authorize($membership, Action::ViewOrganization);

        return array_map(
            [$this, 'present'],
            $this->categories->listForOrg($membership->orgId, $includeArchived),
        );
    }

    /** @return array<string, mixed> */
    public function create(Membership $membership, string $name, string $type, string $ip): array
    {
        $this->policy->authorize($membership, Action::ManageCategories, ip: $ip);

        if ($this->categories->nameExists($membership->orgId, $name)) {
            throw HttpException::conflict("A category called \"{$name}\" already exists.");
        }

        $categoryId = $this->categories->create($membership->orgId, $name, $type);

        $this->activity->record(
            $membership->orgId,
            $membership->userId,
            'category.created',
            'category',
            $categoryId,
            after: ['name' => $name, 'type' => $type],
            ip: $ip,
        );

        return $this->present($this->findOrFail($membership, $categoryId));
    }

    /**
     * @param array<string, mixed> $input only the fields the client actually sent
     *
     * @return array<string, mixed>
     */
    public function update(Membership $membership, int $categoryId, array $input, string $ip): array
    {
        $this->policy->authorize($membership, Action::ManageCategories, ip: $ip);

        $before = $this->findOrFail($membership, $categoryId);

        if (isset($input['name']) && $this->categories->nameExists($membership->orgId, $input['name'], $categoryId)) {
            throw HttpException::conflict("A category called \"{$input['name']}\" already exists.");
        }

        $changes = array_intersect_key($input, array_flip(['name', 'type', 'is_archived']));

        if (array_key_exists('is_archived', $changes)) {
            $changes['is_archived'] = $changes['is_archived'] ? 1 : 0;
        }

        $this->categories->update($membership->orgId, $categoryId, $changes);

        $this->activity->record(
            $membership->orgId,
            $membership->userId,
            'category.updated',
            'category',
            $categoryId,
            before: array_intersect_key($before, $changes),
            after: $changes,
            ip: $ip,
        );

        return $this->present($this->findOrFail($membership, $categoryId));
    }

    /**
     * A category with entries against it is never deleted — that would orphan history.
     * The design says as much: such a row offers Rename only, and archiving is the way to
     * take it out of circulation.
     */
    public function delete(Membership $membership, int $categoryId, string $ip): void
    {
        $this->policy->authorize($membership, Action::ManageCategories, ip: $ip);

        $category = $this->findOrFail($membership, $categoryId);
        $usage = $this->categories->usageCount($membership->orgId, $categoryId);

        if ($usage > 0) {
            throw HttpException::conflict(
                "\"{$category['name']}\" is used by {$usage} entries. Archive it instead of deleting it."
            );
        }

        $this->categories->delete($membership->orgId, $categoryId);

        $this->activity->record(
            $membership->orgId,
            $membership->userId,
            'category.deleted',
            'category',
            $categoryId,
            before: ['name' => $category['name'], 'type' => $category['type']],
            ip: $ip,
        );
    }

    /** @return array<string, mixed> */
    private function findOrFail(Membership $membership, int $categoryId): array
    {
        return $this->categories->find($membership->orgId, $categoryId)
            ?? throw HttpException::notFound('No such category.');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $category = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
            'is_archived' => (bool) $row['is_archived'],
        ];

        if (array_key_exists('entry_count', $row)) {
            $category += [
                'entry_count' => (int) $row['entry_count'],
                'total_out_paisa' => (int) $row['total_out_paisa'],
            ];
        }

        return $category;
    }
}
