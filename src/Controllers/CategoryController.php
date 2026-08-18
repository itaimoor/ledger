<?php

declare(strict_types=1);

namespace Ledger\Controllers;

use Ledger\Domain\CategoryType;
use Ledger\Http\Request;
use Ledger\Http\Response;
use Ledger\Security\Membership;
use Ledger\Services\CategoryService;
use Ledger\Support\Validator;

final class CategoryController
{
    public function __construct(private readonly CategoryService $categories)
    {
    }

    public function index(Request $request, Membership $membership): Response
    {
        $categories = $this->categories->list($membership, $request->query('include_archived') === 'true');

        return Response::ok($categories, ['count' => count($categories)]);
    }

    public function store(Request $request, Membership $membership): Response
    {
        $input = (new Validator($request->body()))
            ->string('name', max: 80, min: 1)
            ->enum('type', $this->types(), required: false)
            ->validate();

        return Response::created($this->categories->create(
            $membership,
            $input['name'],
            $input['type'] ?? CategoryType::Both->value,
            $request->ip,
        ));
    }

    /** @param array<string, string> $params */
    public function update(Request $request, Membership $membership, array $params): Response
    {
        $input = (new Validator($request->body()))
            ->string('name', max: 80, min: 1, required: false)
            ->enum('type', $this->types(), required: false)
            ->bool('is_archived', required: false)
            ->validate();

        return Response::ok(
            $this->categories->update($membership, (int) $params['category'], $input, $request->ip),
        );
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, Membership $membership, array $params): Response
    {
        $this->categories->delete($membership, (int) $params['category'], $request->ip);

        return Response::noContent();
    }

    /** @return list<string> */
    private function types(): array
    {
        return array_column(CategoryType::cases(), 'value');
    }
}
