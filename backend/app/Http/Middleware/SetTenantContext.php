<?php

namespace App\Http\Middleware;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Models\User;
use App\Support\Tenancy\DatabaseTenancy;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the tenant for an authenticated request, from the staff user
 * or the portal client. Runs before route-model binding so bound models are
 * already tenant-scoped (see the priority list in bootstrap/app.php).
 *
 * The tenant is enforced twice: by TenantScope on every Eloquent query, and
 * on PostgreSQL by row-level security, which also covers raw queries.
 */
class SetTenantContext
{
    private const ENROLLMENT_PATHS = ['api/v1/auth/me', 'api/v1/auth/logout', 'api/v1/auth/two-factor', 'api/v1/auth/two-factor/*'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly DatabaseTenancy $database,
    ) {}

    public function handle(Request $request, Closure $next, string $guard = 'sanctum'): Response
    {
        $principal = $request->user($guard);

        if ($principal === null || $principal->firm_id === null) {
            abort(403, 'Your account is not associated with a firm.');
        }

        if ($principal instanceof User && ! $principal->is_active) {
            abort(403, 'Your account has been deactivated.');
        }

        if ($principal instanceof Client && ! $principal->portal_enabled) {
            abort(403, 'Portal access has been disabled for this account.');
        }

        if ($principal instanceof User && $this->mustEnrollInTwoFactor($request, $principal)) {
            return response()->json([
                'status' => 'error',
                'code' => 'two_factor_required',
                'message' => 'Your firm requires two-step verification. Set it up to continue.',
            ], 403);
        }

        $this->context->set((int) $principal->firm_id, $principal);
        $this->database->restrictTo((int) $principal->firm_id);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
            $this->database->bypass();
        }
    }

    /**
     * When the firm requires two-step verification, a user without it can
     * only reach what they need to set it up (or sign out).
     */
    private function mustEnrollInTwoFactor(Request $request, User $user): bool
    {
        if ($user->hasTwoFactorEnabled() || $request->is(self::ENROLLMENT_PATHS)) {
            return false;
        }

        return (bool) Firm::whereKey($user->firm_id)->value('require_two_factor');
    }
}
