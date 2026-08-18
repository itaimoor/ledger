<?php

declare(strict_types=1);

namespace Ledger\Controllers;

use Ledger\Domain\ProjectStatus;
use Ledger\Http\Request;
use Ledger\Http\Response;
use Ledger\Security\Membership;
use Ledger\Services\ProjectService;
use Ledger\Support\Validator;

final class ProjectController
{
    public function __construct(private readonly ProjectService $projects)
    {
    }

    public function index(Request $request, Membership $membership): Response
    {
        $status = $request->query('status');

        $result = $this->projects->list(
            $membership,
            in_array($status, $this->statuses(), true) ? $status : null,
            $request->query('search'),
            $request->query('sort') ?? 'last_activity',
        );

        return Response::ok($result['data'], $result['meta']);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, Membership $membership, array $params): Response
    {
        return Response::ok($this->projects->show($membership, (int) $params['project']));
    }

    public function store(Request $request, Membership $membership): Response
    {
        $input = (new Validator($request->body()))
            ->string('name', max: 160, min: 2)
            ->string('client_name', max: 160, required: false)
            ->string('description', max: 2000, required: false)
            ->enum('status', $this->statuses(), required: false)
            ->validate();

        return Response::created($this->projects->create($membership, $input, $request->ip));
    }

    /** @param array<string, string> $params */
    public function update(Request $request, Membership $membership, array $params): Response
    {
        $input = (new Validator($request->body()))
            ->string('name', max: 160, min: 2, required: false)
            ->string('client_name', max: 160, required: false)
            ->string('description', max: 2000, required: false)
            ->enum('status', $this->statuses(), required: false)
            ->validate();

        return Response::ok(
            $this->projects->update($membership, (int) $params['project'], $input, $request->ip),
        );
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, Membership $membership, array $params): Response
    {
        $this->projects->delete($membership, (int) $params['project'], $request->ip);

        return Response::noContent();
    }

    /** @return list<string> */
    private function statuses(): array
    {
        return array_column(ProjectStatus::cases(), 'value');
    }
}
