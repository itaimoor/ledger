<?php

declare(strict_types=1);

namespace Ledger\Controllers;

use Ledger\Domain\Role;
use Ledger\Http\Request;
use Ledger\Http\Response;
use Ledger\Security\Membership;
use Ledger\Services\AuthService;
use Ledger\Services\MemberService;
use Ledger\Support\Validator;

final class MemberController
{
    public function __construct(private readonly MemberService $members)
    {
    }

    public function index(Request $request, Membership $membership): Response
    {
        $result = $this->members->list($membership);

        return Response::ok($result, [
            'member_count' => count($result['members']),
            'pending_invite_count' => count($result['pending_invites']),
        ]);
    }

    /** Creates the account outright; the response carries the one-time password once. */
    public function store(Request $request, Membership $membership): Response
    {
        $input = (new Validator($request->body()))
            ->string('name', max: 120, min: 2)
            ->email('email')
            ->enum('role', $this->invitableRoles())
            ->string('password', min: AuthService::MINIMUM_PASSWORD_LENGTH, max: 200, required: false)
            ->validate();

        return Response::created($this->members->create(
            $membership,
            $input['name'],
            $input['email'],
            $input['role'],
            $request->ip,
            $input['password'] ?? null,
        ));
    }

    /** @param array<string, string> $params */
    public function updateRole(Request $request, Membership $membership, array $params): Response
    {
        $input = (new Validator($request->body()))
            ->enum('role', $this->invitableRoles())
            ->validate();

        $this->members->changeRole($membership, (int) $params['user'], $input['role'], $request->ip);

        return Response::ok($this->members->list($membership));
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, Membership $membership, array $params): Response
    {
        $this->members->remove($membership, (int) $params['user'], $request->ip);

        return Response::noContent();
    }

    /**
     * An admin replaces a member's password. The response carries the generated one-time
     * password exactly once, or null when the admin typed one.
     *
     * @param array<string, string> $params
     */
    public function resetPassword(Request $request, Membership $membership, array $params): Response
    {
        $input = (new Validator($request->body()))
            ->string('password', min: AuthService::MINIMUM_PASSWORD_LENGTH, max: 200, required: false)
            ->validate();

        return Response::ok($this->members->resetPassword(
            $membership,
            (int) $params['user'],
            $input['password'] ?? null,
            $request->ip,
        ));
    }

    /** Returns a link the admin copies and delivers by hand. */
    public function invite(Request $request, Membership $membership): Response
    {
        $input = (new Validator($request->body()))
            ->email('email')
            ->enum('role', $this->invitableRoles())
            ->validate();

        return Response::created(
            $this->members->invite($membership, $input['email'], $input['role'], $request->ip),
        );
    }

    /** @param array<string, string> $params */
    public function revokeInvite(Request $request, Membership $membership, array $params): Response
    {
        $this->members->revokeInvite($membership, (int) $params['invite'], $request->ip);

        return Response::noContent();
    }

    /** @param array<string, string> $params */
    public function showInvite(Request $request, array $params): Response
    {
        return Response::ok($this->members->previewInvite($params['token']));
    }

    /** @param array<string, string> $params */
    public function acceptInvite(Request $request, array $params): Response
    {
        $input = (new Validator($request->body()))
            ->string('name', max: 120, min: 2, required: false)
            ->string('password', min: AuthService::MINIMUM_PASSWORD_LENGTH, max: 200, required: false)
            ->validate();

        return Response::ok($this->members->acceptInvite(
            $params['token'],
            $input['name'] ?? null,
            $input['password'] ?? null,
            $request->ip,
        ));
    }

    /** @param array<string, string> $params */
    public function declineInvite(Request $request, array $params): Response
    {
        $this->members->declineInvite($params['token'], $request->ip);

        return Response::noContent();
    }

    /** @return list<string> */
    private function invitableRoles(): array
    {
        return array_values(array_map(
            static fn (Role $role): string => $role->value,
            array_filter(Role::cases(), static fn (Role $role): bool => $role->isInvitable()),
        ));
    }
}
