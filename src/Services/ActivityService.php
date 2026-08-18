<?php

declare(strict_types=1);

namespace Ledger\Services;

use Ledger\Repositories\ActivityLogRepository;
use Ledger\Security\Action;
use Ledger\Security\Membership;
use Ledger\Security\Policy;
use Ledger\Support\Cursor;

final class ActivityService
{
    public const DEFAULT_LIMIT = 30;
    public const MAX_LIMIT = 100;

    public function __construct(
        private readonly ActivityLogRepository $activity,
        private readonly Policy $policy,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(Membership $membership, array $filters, ?string $cursor, int $limit): array
    {
        // Readable by every role including Viewer: an audit trail nobody may read is not
        // an audit trail.
        $this->policy->authorize($membership, Action::ViewOrganization);

        $limit = max(1, min($limit, self::MAX_LIMIT));

        $rows = $this->activity->listForOrg(
            $membership->orgId,
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
                    ? Cursor::encode((string) $last['created_at'], (int) $last['id'])
                    : null,
            ],
        ];
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
            'action' => (string) $row['action'],
            // Null for a failed sign-in against an address nobody owns.
            'actor' => $row['user_id'] === null ? null : [
                'id' => (int) $row['user_id'],
                'name' => (string) $row['actor_name'],
            ],
            'entity' => $row['entity_type'] === null ? null : [
                'type' => (string) $row['entity_type'],
                'id' => $row['entity_id'] === null ? null : (int) $row['entity_id'],
            ],
            'project' => $row['project_id'] === null ? null : [
                'id' => (int) $row['project_id'],
                'name' => (string) $row['project_name'],
            ],
            'before' => $this->decode($row['before_json']),
            'after' => $this->decode($row['after_json']),
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * The column holds JSON we wrote ourselves. It is still decoded defensively: a row
     * that somehow holds rubbish must not take the whole page down.
     */
    private function decode(mixed $json): mixed
    {
        if (!is_string($json) || $json === '') {
            return null;
        }

        return json_decode($json, true) ?? null;
    }
}
