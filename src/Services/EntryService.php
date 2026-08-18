<?php

declare(strict_types=1);

namespace Ledger\Services;

use Ledger\Domain\CategoryType;
use Ledger\Domain\EntryType;
use Ledger\Exceptions\HttpException;
use Ledger\Exceptions\ValidationException;
use Ledger\Repositories\ActivityLogRepository;
use Ledger\Repositories\CategoryRepository;
use Ledger\Repositories\EntryRepository;
use Ledger\Security\Action;
use Ledger\Security\Policy;
use Ledger\Security\ProjectContext;
use Ledger\Support\Cursor;

final class EntryService
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    public function __construct(
        private readonly EntryRepository $entries,
        private readonly CategoryRepository $categories,
        private readonly ActivityLogRepository $activity,
        private readonly Policy $policy,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(ProjectContext $context, array $filters, ?string $cursor, int $limit): array
    {
        $this->policy->authorize($context->membership, Action::ViewOrganization);

        $limit = max(1, min($limit, self::MAX_LIMIT));

        // One row beyond the page, purely to answer "is there more?" without a count.
        $rows = $this->entries->page(
            $context->membership->orgId,
            $context->projectId,
            $filters,
            Cursor::decode($cursor),
            $limit + 1,
        );

        $hasMore = count($rows) > $limit;
        $page = $hasMore ? array_slice($rows, 0, $limit) : $rows;
        $last = $page === [] ? null : $page[array_key_last($page)];

        return [
            'data' => array_map([$this, 'present'], $page),
            'meta' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_cursor' => $hasMore && $last !== null
                    ? Cursor::encode((string) $last['entry_date'], (int) $last['id'])
                    : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function summary(ProjectContext $context): array
    {
        $this->policy->authorize($context->membership, Action::ViewOrganization);

        return $this->entries->summary($context->membership->orgId, $context->projectId);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function create(ProjectContext $context, array $input, string $ip): array
    {
        $this->policy->authorize($context->membership, Action::CreateEntry, $context->status, $ip);

        $type = EntryType::from((string) $input['type']);
        $categoryId = $this->resolveCategory($context, $type, $input['category_id'] ?? null);

        $entryId = $this->entries->create(
            $context->membership->orgId,
            $context->projectId,
            $type->value,
            (int) $input['amount_paisa'],
            $categoryId,
            $input['description'] ?? null,
            (string) $input['entry_date'],
            null,
            $context->membership->userId,
        );

        $this->activity->record(
            $context->membership->orgId,
            $context->membership->userId,
            'entry.created',
            'entry',
            $entryId,
            after: [
                'project_id' => $context->projectId,
                'type' => $type->value,
                'amount_paisa' => (int) $input['amount_paisa'],
                'entry_date' => $input['entry_date'],
            ],
            ip: $ip,
        );

        return $this->presentOne($context, $entryId);
    }

    /**
     * Corrects an entry by posting its opposite, rather than by changing it.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function reconcile(ProjectContext $context, int $targetId, array $input, string $ip): array
    {
        $this->policy->authorize($context->membership, Action::ReconcileEntry, $context->status, $ip);

        $target = $this->entries->find($context->membership->orgId, $context->projectId, $targetId)
            ?? throw HttpException::notFound('No such entry.');

        // ponytail: one correction per entry. Two reconciliations against the same row is
        // nearly always a double-correction mistake, and the escape hatch is to reconcile
        // the correction itself. Lift this if partial corrections turn out to be normal.
        if ($this->entries->hasReconciliation($context->membership->orgId, $targetId)) {
            throw HttpException::conflict('That entry has already been reconciled.');
        }

        $amount = isset($input['amount_paisa']) ? (int) $input['amount_paisa'] : (int) $target['amount_paisa'];

        if ($amount > (int) $target['amount_paisa']) {
            throw new ValidationException([
                'amount_paisa' => 'A correction cannot exceed the entry it corrects.',
            ]);
        }

        $opposite = EntryType::from((string) $target['type'])->opposite();

        // No category is required on a correction: it inherits its meaning from the entry
        // it points at, and forcing one would invent a category that never happened.
        $entryId = $this->entries->create(
            $context->membership->orgId,
            $context->projectId,
            $opposite->value,
            $amount,
            null,
            $input['description'] ?? 'Reconciles entry #' . $targetId,
            (string) ($input['entry_date'] ?? date('Y-m-d')),
            $targetId,
            $context->membership->userId,
        );

        $this->activity->record(
            $context->membership->orgId,
            $context->membership->userId,
            'entry.reconciled',
            'entry',
            $entryId,
            before: [
                'entry_id' => $targetId,
                'type' => $target['type'],
                'amount_paisa' => (int) $target['amount_paisa'],
            ],
            after: ['type' => $opposite->value, 'amount_paisa' => $amount],
            ip: $ip,
        );

        return $this->presentOne($context, $entryId);
    }

    /**
     * A category must belong to this organization, still be in circulation, and accept
     * this direction of money. Anything else is a 422 against the field, not a 500 from
     * the foreign key.
     */
    private function resolveCategory(ProjectContext $context, EntryType $type, mixed $categoryId): ?int
    {
        if ($categoryId === null) {
            return $type->requiresCategory()
                ? throw new ValidationException(['category_id' => 'Money out needs a category.'])
                : null;
        }

        $category = $this->categories->find($context->membership->orgId, (int) $categoryId);

        if ($category === null) {
            throw new ValidationException(['category_id' => 'No such category.']);
        }

        if ((bool) $category['is_archived']) {
            throw new ValidationException(['category_id' => 'That category has been archived.']);
        }

        if (!CategoryType::from((string) $category['type'])->allows($type)) {
            throw new ValidationException([
                'category_id' => "\"{$category['name']}\" cannot be used for money {$type->value}.",
            ]);
        }

        return (int) $category['id'];
    }

    /**
     * Re-read through the same paged query so the response carries the running balance,
     * rather than a bare INSERT echo the client would have to reconcile itself.
     *
     * @return array<string, mixed>
     */
    private function presentOne(ProjectContext $context, int $entryId): array
    {
        $rows = $this->entries->page(
            $context->membership->orgId,
            $context->projectId,
            ['id' => $entryId],
            null,
            1,
        );

        return $rows === []
            ? throw HttpException::notFound('No such entry.')
            : $this->present($rows[0]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'entry_date' => (string) $row['entry_date'],
            'type' => (string) $row['type'],
            'amount_paisa' => (int) $row['amount_paisa'],
            'running_balance_paisa' => (int) $row['running_balance_paisa'],
            'category' => $row['category_id'] === null ? null : [
                'id' => (int) $row['category_id'],
                'name' => (string) $row['category_name'],
            ],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'created_by' => [
                'id' => (int) $row['created_by'],
                'name' => (string) $row['created_by_name'],
            ],
            'created_at' => (string) $row['created_at'],
            'reconciles_entry_id' => $row['reconciles_entry_id'] === null
                ? null
                : (int) $row['reconciles_entry_id'],
            'is_reconciled' => (bool) $row['is_reconciled'],
        ];
    }
}
