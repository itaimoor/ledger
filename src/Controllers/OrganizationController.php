<?php

declare(strict_types=1);

namespace Ledger\Controllers;

use Ledger\Auth\AuthenticatedUser;
use Ledger\Http\Request;
use Ledger\Http\Response;
use Ledger\Security\Membership;
use Ledger\Services\OrganizationService;
use Ledger\Support\Validator;

final class OrganizationController
{
    public function __construct(private readonly OrganizationService $organizations)
    {
    }

    public function index(Request $request, AuthenticatedUser $user): Response
    {
        $organizations = $this->organizations->listForUser($user->id);

        return Response::ok($organizations, ['count' => count($organizations)]);
    }

    public function store(Request $request, AuthenticatedUser $user): Response
    {
        $input = (new Validator($request->body()))
            ->string('name', max: 120, min: 2)
            ->validate();

        return Response::created($this->organizations->create($user, $input['name'], $request->ip));
    }

    public function show(Request $request, Membership $membership): Response
    {
        return Response::ok($this->organizations->show($membership));
    }

    public function update(Request $request, Membership $membership): Response
    {
        $input = (new Validator($request->body()))
            ->string('name', max: 120, min: 2)
            ->validate();

        $this->organizations->rename($membership, $input['name'], $request->ip);

        return Response::ok($this->organizations->show($membership));
    }

    public function destroy(Request $request, Membership $membership): Response
    {
        $this->organizations->delete($membership, $request->ip);

        return Response::noContent();
    }
}
