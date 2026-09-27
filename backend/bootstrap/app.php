<?php

use App\Domain\Trust\Exceptions\InsufficientTrustFunds;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cookie-based session auth for the first-party SPA (Sanctum).
        $middleware->statefulApi();

        // The TLS proxy (Caddy) and nginx in front of PHP. Only these may set
        // X-Forwarded-For/-Proto, or anyone could spoof their IP (rate limits,
        // audit log). Docker sets TRUSTED_PROXIES to the private ranges.
        if (filled(env('TRUSTED_PROXIES'))) {
            $middleware->trustProxies(at: array_map('trim', explode(',', (string) env('TRUSTED_PROXIES'))));
        }

        $middleware->alias(['tenant' => SetTenantContext::class]);

        // The API never redirects guests (there is no server-rendered login
        // page); an unauthenticated API call is a 401, whatever it accepts.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');

        // The tenant must be known before route-model binding runs, so that
        // a bound {matter} from another firm resolves to a 404.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetTenantContext::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Error tracking; inert unless SENTRY_LARAVEL_DSN is set.
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        // One error envelope for the whole API: {status, message, errors?}.
        $error = fn (int $status, string $message, array $extra = []) => response()->json(
            ['status' => 'error', 'message' => $message, ...$extra],
            $status,
        );

        $exceptions->render(fn (ValidationException $e, Request $request) => $request->is('api/*')
            ? $error(422, $e->getMessage(), ['errors' => $e->errors()])
            : null);

        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/*')
            ? $error(401, 'Unauthenticated.')
            : null);

        $exceptions->render(fn (AuthorizationException|AccessDeniedHttpException $e, Request $request) => $request->is('api/*')
            ? $error(403, $e->getMessage() ?: 'This action is unauthorized.')
            : null);

        $exceptions->render(fn (ModelNotFoundException|NotFoundHttpException $e, Request $request) => $request->is('api/*')
            ? $error(404, 'Resource not found.')
            : null);

        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => $request->is('api/*')
            ? $error(429, 'Too many attempts. Please wait a moment and try again.')
            : null);

        $exceptions->render(fn (InsufficientTrustFunds $e, Request $request) => $request->is('api/*')
            ? $error(422, $e->getMessage(), ['errors' => ['amount' => [$e->getMessage()]]])
            : null);

        $exceptions->render(fn (HttpExceptionInterface $e, Request $request) => $request->is('api/*')
            ? $error($e->getStatusCode(), $e->getMessage() ?: 'Request failed.')
            : null);
    })->create();
