<?php

/**
 * The routing table. Included by public/index.php, returns a Router.
 *
 * Wiring is done by hand. There are few enough collaborators that a container would only
 * add indirection, and every object here is a thin wrapper with no work in its constructor.
 *
 * Handler shapes:
 *   public                 fn (Request $request, array $params): Response
 *   $authed / $writing     fn (Request $request, AuthenticatedUser $user, array $params): Response
 *   $inOrg / $inOrgWriting fn (Request $request, Membership $membership, array $params): Response
 */

declare(strict_types=1);

use Ledger\Auth\Jwt;
use Ledger\Auth\RateLimiter;
use Ledger\Auth\TokenService;
use Ledger\Controllers\ActivityController;
use Ledger\Controllers\AuthController;
use Ledger\Controllers\CategoryController;
use Ledger\Controllers\EntryController;
use Ledger\Controllers\MemberController;
use Ledger\Controllers\OrganizationController;
use Ledger\Controllers\ProjectController;
use Ledger\Controllers\ReportController;
use Ledger\Domain\ProjectStatus;
use Ledger\Domain\Role;
use Ledger\Exceptions\HttpException;
use Ledger\Http\Middleware\Authenticate;
use Ledger\Http\Request;
use Ledger\Http\Response;
use Ledger\Http\Router;
use Ledger\Repositories\ActivityLogRepository;
use Ledger\Repositories\CategoryRepository;
use Ledger\Repositories\EntryRepository;
use Ledger\Repositories\InviteRepository;
use Ledger\Repositories\MembershipRepository;
use Ledger\Repositories\OrganizationRepository;
use Ledger\Repositories\ProjectRepository;
use Ledger\Repositories\RefreshTokenRepository;
use Ledger\Repositories\ReportRepository;
use Ledger\Repositories\UserRepository;
use Ledger\Security\Membership;
use Ledger\Security\Policy;
use Ledger\Security\ProjectContext;
use Ledger\Services\ActivityService;
use Ledger\Services\AuthService;
use Ledger\Services\CategoryService;
use Ledger\Services\EntryService;
use Ledger\Services\MemberService;
use Ledger\Services\OrganizationService;
use Ledger\Services\ProjectService;
use Ledger\Services\ReportService;
use Ledger\Support\Database;
use Ledger\Support\Env;

$pdo = Database::connect();

$activityLog = new ActivityLogRepository($pdo);
$categoryRepository = new CategoryRepository($pdo);
$inviteRepository = new InviteRepository($pdo);
$membershipRepository = new MembershipRepository($pdo);
$organizationRepository = new OrganizationRepository($pdo);
$projectRepository = new ProjectRepository($pdo);
$refreshTokens = new RefreshTokenRepository($pdo);
$users = new UserRepository($pdo);

$policy = new Policy($activityLog);
$jwt = new Jwt(Env::required('JWT_SECRET'), Env::string('JWT_ISSUER', 'ledger'));
$limiter = new RateLimiter($pdo, Env::int('RATE_WINDOW', 300));

$tokens = new TokenService(
    $jwt,
    $refreshTokens,
    $activityLog,
    $pdo,
    Env::int('JWT_ACCESS_TTL', 900),
    Env::int('REFRESH_TTL_DAYS', 30),
);

$organizationService = new OrganizationService(
    $organizationRepository,
    $membershipRepository,
    $categoryRepository,
    $activityLog,
    $policy,
    $pdo,
);

$authService = new AuthService(
    $users,
    $tokens,
    $limiter,
    $activityLog,
    $organizationService,
    $pdo,
    Env::int('RATE_LOGIN_PER_IP', 30),
    Env::int('RATE_LOGIN_PER_EMAIL', 5),
    Env::int('RATE_REGISTER_PER_IP', 5),
);

$memberService = new MemberService(
    $membershipRepository,
    $users,
    $inviteRepository,
    $refreshTokens,
    $activityLog,
    $policy,
    $pdo,
    Env::string('APP_URL', 'http://localhost:8000'),
    Env::int('INVITE_TTL_HOURS', 72),
);

$authenticate = new Authenticate($jwt, $users);
$writeBudget = Env::int('RATE_WRITE_PER_USER', 120);

/** Runs the handler only for a caller holding a valid access token. */
$authed = static fn (callable $handler): callable
    => static fn (Request $request, array $params): Response
        => $handler($request, $authenticate($request), $params);

/** As $authed, and spends one unit of the caller's write budget. */
$writing = static function (callable $handler) use ($authenticate, $limiter, $writeBudget): callable {
    return static function (Request $request, array $params) use ($handler, $authenticate, $limiter, $writeBudget) {
        $user = $authenticate($request);
        $limiter->hit(RateLimiter::writesByUser($user->id), $writeBudget);

        return $handler($request, $user, $params);
    };
};

/**
 * The tenant gate. Turns the {org} in the path into a Membership read from the database,
 * and answers 404 when there is none — the caller learns nothing about whether the
 * organization exists. Every org-scoped handler receives its org_id from this object and
 * never from the URL.
 */
$resolveMembership = static function (Request $request, array $params) use ($authenticate, $membershipRepository) {
    $user = $authenticate($request);

    return $membershipRepository->find((int) ($params['org'] ?? 0), $user->id)
        ?? throw HttpException::notFound();
};

$inOrg = static fn (callable $handler): callable
    => static fn (Request $request, array $params): Response
        => $handler($request, $resolveMembership($request, $params), $params);

$inOrgWriting = static function (callable $handler) use ($resolveMembership, $limiter, $writeBudget): callable {
    $spendBudget = static function (Request $request, array $params) use ($resolveMembership, $limiter, $writeBudget) {
        $membership = $resolveMembership($request, $params);
        $limiter->hit(RateLimiter::writesByUser($membership->userId), $writeBudget);

        return $membership;
    };

    return static fn (Request $request, array $params): Response
        => $handler($request, $spendBudget($request, $params), $params);
};

/**
 * The tenant gate for routes addressed by project id alone. One query resolves the
 * project, the caller's role in its organization, and the project's status; no row means
 * 404, indistinguishable from a project that was never created.
 */
$resolveProject = static function (Request $request, array $params) use ($authenticate, $projectRepository) {
    $user = $authenticate($request);
    $row = $projectRepository->findForUser((int) ($params['project'] ?? 0), $user->id)
        ?? throw HttpException::notFound();

    return new ProjectContext(
        new Membership((int) $row['org_id'], $user->id, Role::from((string) $row['role'])),
        (int) $row['id'],
        (string) $row['name'],
        ProjectStatus::from((string) $row['status']),
    );
};

$inProject = static fn (callable $handler): callable
    => static fn (Request $request, array $params): Response
        => $handler($request, $resolveProject($request, $params), $params);

$inProjectWriting = static function (callable $handler) use ($resolveProject, $limiter, $writeBudget): callable {
    $spendBudget = static function (Request $request, array $params) use ($resolveProject, $limiter, $writeBudget) {
        $context = $resolveProject($request, $params);
        $limiter->hit(RateLimiter::writesByUser($context->membership->userId), $writeBudget);

        return $context;
    };

    return static fn (Request $request, array $params): Response
        => $handler($request, $spendBudget($request, $params), $params);
};

$auth = new AuthController($authService);
$organizations = new OrganizationController($organizationService);
$members = new MemberController($memberService);

$projects = new ProjectController(new ProjectService($projectRepository, $activityLog, $policy));
$categories = new CategoryController(new CategoryService($categoryRepository, $activityLog, $policy));

$entries = new EntryController(new EntryService(
    new EntryRepository($pdo),
    $categoryRepository,
    $activityLog,
    $policy,
));

$reports = new ReportController(
    new ReportService(new ReportRepository($pdo), $projectRepository, $policy),
);

$activity = new ActivityController(new ActivityService($activityLog, $policy));

$router = new Router();

$router->post('/api/v1/auth/register', $auth->register(...));
$router->post('/api/v1/auth/login', $auth->login(...));
$router->post('/api/v1/auth/refresh', $auth->refresh(...));
$router->post('/api/v1/auth/logout', $auth->logout(...));
$router->post('/api/v1/auth/password', $writing($auth->changePassword(...)));

$router->get('/api/v1/me', $authed($auth->me(...)));
$router->patch('/api/v1/me', $writing($auth->updateProfile(...)));

$router->get('/api/v1/organizations', $authed($organizations->index(...)));
$router->post('/api/v1/organizations', $writing($organizations->store(...)));
$router->get('/api/v1/organizations/{org}', $inOrg($organizations->show(...)));
$router->patch('/api/v1/organizations/{org}', $inOrgWriting($organizations->update(...)));
$router->delete('/api/v1/organizations/{org}', $inOrgWriting($organizations->destroy(...)));

$router->get('/api/v1/organizations/{org}/projects', $inOrg($projects->index(...)));
$router->post('/api/v1/organizations/{org}/projects', $inOrgWriting($projects->store(...)));
$router->get('/api/v1/organizations/{org}/projects/{project}', $inOrg($projects->show(...)));
$router->patch('/api/v1/organizations/{org}/projects/{project}', $inOrgWriting($projects->update(...)));
$router->delete('/api/v1/organizations/{org}/projects/{project}', $inOrgWriting($projects->destroy(...)));

$router->get('/api/v1/organizations/{org}/categories', $inOrg($categories->index(...)));
$router->post('/api/v1/organizations/{org}/categories', $inOrgWriting($categories->store(...)));
$router->patch('/api/v1/organizations/{org}/categories/{category}', $inOrgWriting($categories->update(...)));
$router->delete('/api/v1/organizations/{org}/categories/{category}', $inOrgWriting($categories->destroy(...)));

$router->get('/api/v1/organizations/{org}/members', $inOrg($members->index(...)));
$router->post('/api/v1/organizations/{org}/members', $inOrgWriting($members->store(...)));
$router->patch('/api/v1/organizations/{org}/members/{user}', $inOrgWriting($members->updateRole(...)));
$router->delete('/api/v1/organizations/{org}/members/{user}', $inOrgWriting($members->destroy(...)));
$router->post(
    '/api/v1/organizations/{org}/members/{user}/password',
    $inOrgWriting($members->resetPassword(...)),
);

$router->get('/api/v1/organizations/{org}/reports/cashflow', $inOrg($reports->cashflow(...)));
$router->get('/api/v1/organizations/{org}/reports/categories', $inOrg($reports->outByCategory(...)));
$router->get('/api/v1/organizations/{org}/activity', $inOrg($activity->index(...)));

$router->post('/api/v1/organizations/{org}/invites', $inOrgWriting($members->invite(...)));
$router->delete('/api/v1/organizations/{org}/invites/{invite}', $inOrgWriting($members->revokeInvite(...)));

// Addressed by project id alone, per the API contract: the organization is derived from
// the project, never taken from the caller.
$router->get('/api/v1/projects/{project}/entries', $inProject($entries->index(...)));
$router->post('/api/v1/projects/{project}/entries', $inProjectWriting($entries->store(...)));
$router->get('/api/v1/projects/{project}/summary', $inProject($entries->summary(...)));
$router->post(
    '/api/v1/projects/{project}/entries/{entry}/reconcile',
    $inProjectWriting($entries->reconcile(...)),
);

// Public: the recipient holds only the token and has no account yet.
$router->get('/api/v1/invites/{token}', $members->showInvite(...));
$router->post('/api/v1/invites/{token}/accept', $members->acceptInvite(...));
$router->post('/api/v1/invites/{token}/decline', $members->declineInvite(...));

return $router;
