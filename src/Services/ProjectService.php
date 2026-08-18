<?php

declare(strict_types=1);

namespace Ledger\Services;

use Ledger\Domain\ProjectStatus;
use Ledger\Exceptions\HttpException;
use Ledger\Repositories\ActivityLogRepository;
use Ledger\Repositories\ProjectRepository;
use Ledger\Security\Action;
use Ledger\Security\Membership;
use Ledger\Security\Policy;

final class ProjectService
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ActivityLogRepository $activity,
        private readonly Policy $policy,
    ) {
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, mixed>} */
    public function list(Membership $membership, ?string $status, ?string $search, string $sort): array
    {
        $this->policy->authorize($membership, Action::ViewOrganization);

        $rows = $this->projects->listForOrg($membership->orgId, $status, $search, $sort);

        return [
            'data' => array_map([$this, 'present'], $rows),
            'meta' => [
                'count' => count($rows),
                'totals' => $this->projects->orgTotals($membership->orgId),
                'status_counts' => $this->projects->statusCounts($membership->orgId),
            ],
        ];
    }

    /**
     * The single choke point for "this project, in this tenant".
     *
     * A project belonging to another organization is indistinguishable from one that does
     * not exist: both produce 404, so the response never confirms the id is real.
     *
     * @return array<string, mixed>
     */
    public function findOrFail(Membership $membership, int $projectId): array
    {
        return $this->projects->find($membership->orgId, $projectId)
            ?? throw HttpException::notFound('No such project.');
    }

    /** @return array<string, mixed> */
    public function show(Membership $membership, int $projectId): array
    {
        $this->policy->authorize($membership, Action::ViewOrganization);

        return $this->present($this->findOrFail($membership, $projectId));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function create(Membership $membership, array $input, string $ip): array
    {
        $this->policy->authorize($membership, Action::CreateProject, ip: $ip);

        $projectId = $this->projects->create(
            $membership->orgId,
            $input['name'],
            $input['client_name'] ?? null,
            $input['description'] ?? null,
            $input['status'] ?? ProjectStatus::Active->value,
            $membership->userId,
        );

        $created = $this->findOrFail($membership, $projectId);

        $this->activity->record(
            $membership->orgId,
            $membership->userId,
            'project.created',
            'project',
            $projectId,
            after: ['name' => $created['name'], 'status' => $created['status']],
            ip: $ip,
        );

        return $this->present($created);
    }

    /**
     * @param array<string, mixed> $input only the fields the client actually sent
     *
     * @return array<string, mixed>
     */
    public function update(Membership $membership, int $projectId, array $input, string $ip): array
    {
        $this->policy->authorize($membership, Action::UpdateProject, ip: $ip);

        $before = $this->findOrFail($membership, $projectId);

        $changes = array_intersect_key($input, array_flip(['name', 'client_name', 'description', 'status']));

        if ($changes !== []) {
            $this->projects->update($membership->orgId, $projectId, $changes);
        }

        $after = $this->findOrFail($membership, $projectId);

        $this->activity->record(
            $membership->orgId,
            $membership->userId,
            $this->wasArchived($before, $after) ? 'project.archived' : 'project.updated',
            'project',
            $projectId,
            before: array_intersect_key($before, $changes),
            after: $changes,
            ip: $ip,
        );

        return $this->present($after);
    }

    public function delete(Membership $membership, int $projectId, string $ip): void
    {
        $this->policy->authorize($membership, Action::DeleteProject, ip: $ip);

        $project = $this->findOrFail($membership, $projectId);
        $this->projects->softDelete($membership->orgId, $projectId);

        $this->activity->record(
            $membership->orgId,
            $membership->userId,
            'project.deleted',
            'project',
            $projectId,
            before: ['name' => $project['name'], 'status' => $project['status']],
            ip: $ip,
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $project = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'client_name' => $row['client_name'] === null ? null : (string) $row['client_name'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];

        // Only the list query carries aggregates; a single-row fetch does not.
        if (array_key_exists('total_in_paisa', $row)) {
            $project += [
                'total_in_paisa' => (int) $row['total_in_paisa'],
                'total_out_paisa' => (int) $row['total_out_paisa'],
                'balance_paisa' => (int) $row['balance_paisa'],
                'entry_count' => (int) $row['entry_count'],
                'last_entry_at' => $row['last_entry_at'] === null ? null : (string) $row['last_entry_at'],
            ];
        }

        return $project;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function wasArchived(array $before, array $after): bool
    {
        return $before['status'] !== ProjectStatus::Archived->value
            && $after['status'] === ProjectStatus::Archived->value;
    }
}
