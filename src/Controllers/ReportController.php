<?php

declare(strict_types=1);

namespace Ledger\Controllers;

use Ledger\Http\Request;
use Ledger\Http\Response;
use Ledger\Repositories\ReportRepository;
use Ledger\Security\Membership;
use Ledger\Services\ReportService;
use Ledger\Support\Validator;

final class ReportController
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    public function cashflow(Request $request, Membership $membership): Response
    {
        $interval = $request->query('interval') ?? 'monthly';

        if (!array_key_exists($interval, ReportRepository::INTERVALS)) {
            $interval = 'monthly';
        }

        $result = $this->reports->cashflow($membership, $interval, $this->filters($request));

        return Response::ok($result['data'], $result['meta']);
    }

    public function outByCategory(Request $request, Membership $membership): Response
    {
        $result = $this->reports->outByCategory($membership, $this->filters($request));

        return Response::ok($result['data'], $result['meta']);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $raw = [];

        foreach (['from', 'to'] as $name) {
            $value = $request->query($name);

            if ($value !== null) {
                $raw[$name] = $value;
            }
        }

        $projectId = $request->query('project_id');

        if ($projectId !== null) {
            $raw['project_id'] = ctype_digit($projectId) ? (int) $projectId : $projectId;
        }

        $filters = (new Validator($raw))
            ->date('from', required: false)
            ->date('to', required: false)
            ->id('project_id', required: false)
            ->validate();

        return array_filter($filters, static fn (mixed $value): bool => $value !== null);
    }
}
