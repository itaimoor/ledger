<?php

declare(strict_types=1);

namespace Ledger\Controllers;

use Ledger\Http\Request;
use Ledger\Http\Response;
use Ledger\Security\Membership;
use Ledger\Services\ActivityService;
use Ledger\Support\Validator;

final class ActivityController
{
    private const ENTITY_TYPES = ['entry', 'project', 'category', 'user', 'organization', 'invite'];

    public function __construct(private readonly ActivityService $activity)
    {
    }

    public function index(Request $request, Membership $membership): Response
    {
        $filters = (new Validator($this->rawFilters($request)))
            ->id('user_id', required: false)
            ->string('action', max: 64, required: false)
            ->enum('entity_type', self::ENTITY_TYPES, required: false)
            ->date('from', required: false)
            ->date('to', required: false)
            ->validate();

        $result = $this->activity->list(
            $membership,
            array_filter($filters, static fn (mixed $value): bool => $value !== null),
            $request->query('cursor'),
            (int) ($request->query('limit') ?? ActivityService::DEFAULT_LIMIT),
        );

        return Response::ok($result['data'], $result['meta']);
    }

    /** @return array<string, mixed> */
    private function rawFilters(Request $request): array
    {
        $filters = [];

        foreach (['action', 'entity_type', 'from', 'to'] as $name) {
            $value = $request->query($name);

            if ($value !== null) {
                $filters[$name] = $value;
            }
        }

        $userId = $request->query('user_id');

        if ($userId !== null) {
            $filters['user_id'] = ctype_digit($userId) ? (int) $userId : $userId;
        }

        return $filters;
    }
}
