<?php

declare(strict_types=1);

namespace Ledger\Controllers;

use Ledger\Domain\EntryType;
use Ledger\Http\Request;
use Ledger\Http\Response;
use Ledger\Security\ProjectContext;
use Ledger\Services\EntryService;
use Ledger\Support\Validator;

final class EntryController
{
    /** Guards against a typo like 2260 without forbidding a postdated cheque. */
    private const EARLIEST_ENTRY_DATE = '2000-01-01';

    public function __construct(private readonly EntryService $entries)
    {
    }

    public function index(Request $request, ProjectContext $context): Response
    {
        $filters = (new Validator($this->queryFilters($request)))
            ->enum('type', $this->types(), required: false)
            ->id('category_id', required: false)
            ->date('from', required: false)
            ->date('to', required: false)
            ->string('search', max: 200, required: false)
            ->validate();

        $result = $this->entries->list(
            $context,
            array_filter($filters, static fn (mixed $value): bool => $value !== null),
            $request->query('cursor'),
            (int) ($request->query('limit') ?? EntryService::DEFAULT_LIMIT),
        );

        return Response::ok($result['data'], $result['meta']);
    }

    public function summary(Request $request, ProjectContext $context): Response
    {
        return Response::ok($this->entries->summary($context));
    }

    public function store(Request $request, ProjectContext $context): Response
    {
        $input = (new Validator($request->body()))
            ->enum('type', $this->types())
            ->int('amount_paisa', min: 1, max: PHP_INT_MAX)
            ->date('entry_date', min: self::EARLIEST_ENTRY_DATE, max: $this->latestEntryDate())
            ->id('category_id', required: false)
            ->string('description', max: 500, required: false)
            ->validate();

        return Response::created($this->entries->create($context, $input, $request->ip));
    }

    /** @param array<string, string> $params */
    public function reconcile(Request $request, ProjectContext $context, array $params): Response
    {
        $input = (new Validator($request->body()))
            ->int('amount_paisa', min: 1, max: PHP_INT_MAX, required: false)
            ->date('entry_date', min: self::EARLIEST_ENTRY_DATE, max: $this->latestEntryDate(), required: false)
            ->string('description', max: 500, required: false)
            ->validate();

        return Response::created(
            $this->entries->reconcile($context, (int) $params['entry'], $input, $request->ip),
        );
    }

    /**
     * Query strings arrive as strings; the validator is strict about types. category_id
     * is promoted to an int here so "7" is accepted from a URL while a JSON body still
     * has to send a number.
     *
     * @return array<string, mixed>
     */
    private function queryFilters(Request $request): array
    {
        $filters = [];

        foreach (['type', 'from', 'to', 'search'] as $name) {
            $value = $request->query($name);

            if ($value !== null) {
                $filters[$name] = $value;
            }
        }

        $categoryId = $request->query('category_id');

        if ($categoryId !== null) {
            $filters['category_id'] = ctype_digit($categoryId) ? (int) $categoryId : $categoryId;
        }

        return $filters;
    }

    private function latestEntryDate(): string
    {
        return date('Y-m-d', strtotime('+1 year'));
    }

    /** @return list<string> */
    private function types(): array
    {
        return array_column(EntryType::cases(), 'value');
    }
}
