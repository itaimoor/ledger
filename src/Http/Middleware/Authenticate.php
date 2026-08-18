<?php

declare(strict_types=1);

namespace Ledger\Http\Middleware;

use Ledger\Auth\AuthenticatedUser;
use Ledger\Auth\Jwt;
use Ledger\Exceptions\HttpException;
use Ledger\Http\Request;
use Ledger\Repositories\UserRepository;

/**
 * Turns a bearer token into an AuthenticatedUser, or refuses the request.
 *
 * The user row is re-read on every request rather than trusted from the token's claims,
 * so suspending an account takes effect immediately instead of at the end of the access
 * token's lifetime.
 */
final class Authenticate
{
    public function __construct(
        private readonly Jwt $jwt,
        private readonly UserRepository $users,
    ) {
    }

    public function __invoke(Request $request): AuthenticatedUser
    {
        $token = $request->bearerToken();

        if ($token === null) {
            throw HttpException::unauthorized();
        }

        $user = $this->users->findActiveById($this->jwt->verify($token));

        if ($user === null) {
            throw HttpException::unauthorized();
        }

        $this->users->touchLastSeen((int) $user['id']);

        return new AuthenticatedUser(
            (int) $user['id'],
            (string) $user['name'],
            (string) $user['email'],
            (bool) $user['must_change_password'],
        );
    }
}
