<?php

declare(strict_types=1);

namespace Ledger\Controllers;

use Ledger\Auth\AuthenticatedUser;
use Ledger\Http\Request;
use Ledger\Http\Response;
use Ledger\Services\AuthService;
use Ledger\Support\Validator;

final class AuthController
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function login(Request $request): Response
    {
        $input = (new Validator($request->body()))
            ->email('email')
            ->string('password', max: 200)
            ->validate();

        return Response::ok($this->auth->login(
            $input['email'],
            $input['password'],
            $request->ip,
            $request->userAgent(),
        ));
    }

    public function register(Request $request): Response
    {
        $input = (new Validator($request->body()))
            ->string('name', max: 120, min: 2)
            ->email('email')
            ->string('password', min: AuthService::MINIMUM_PASSWORD_LENGTH, max: 200)
            ->string('organization_name', max: 120, min: 2)
            ->validate();

        return Response::created($this->auth->register(
            $input['name'],
            $input['email'],
            $input['password'],
            $input['organization_name'],
            $request->ip,
            $request->userAgent(),
        ));
    }

    public function refresh(Request $request): Response
    {
        $input = (new Validator($request->body()))
            ->string('refresh_token', max: 128)
            ->validate();

        return Response::ok($this->auth->refresh(
            $input['refresh_token'],
            $request->ip,
            $request->userAgent(),
        ));
    }

    public function logout(Request $request): Response
    {
        $input = (new Validator($request->body()))
            ->string('refresh_token', max: 128)
            ->validate();

        $this->auth->logout($input['refresh_token']);

        return Response::noContent();
    }

    public function me(Request $request, AuthenticatedUser $user): Response
    {
        return Response::ok([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'must_change_password' => $user->mustChangePassword,
        ]);
    }

    public function updateProfile(Request $request, AuthenticatedUser $user): Response
    {
        $input = (new Validator($request->body()))
            ->string('name', max: 120, min: 2)
            ->validate();

        return Response::ok($this->auth->updateName($user, $input['name'], $request->ip));
    }

    public function changePassword(Request $request, AuthenticatedUser $user): Response
    {
        $input = (new Validator($request->body()))
            ->string('current_password', max: 200)
            ->string('new_password', min: AuthService::MINIMUM_PASSWORD_LENGTH, max: 200)
            ->validate();

        $this->auth->changePassword(
            $user,
            $input['current_password'],
            $input['new_password'],
            $request->ip,
        );

        return Response::noContent();
    }
}
