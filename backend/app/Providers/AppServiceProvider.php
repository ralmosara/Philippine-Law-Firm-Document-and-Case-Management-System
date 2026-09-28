<?php

namespace App\Providers;

use App\Domain\Billing\Models\DisbursementRequest;
use App\Domain\Billing\Models\Expense;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoicePayment;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Compliance\Models\McleCredit;
use App\Domain\Corporate\Models\CorporateObligation;
use App\Domain\Corporate\Models\CorporateProfile;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Deadlines\Services\DeadlineCalculator;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Documents\Models\SignatureRequest;
use App\Domain\Documents\Requests\DocumentRequest;
use App\Domain\Documents\Scanning\ClamAvScanner;
use App\Domain\Documents\Scanning\NullVirusScanner;
use App\Domain\Documents\Scanning\VirusScanner;
use App\Domain\Evidence\Models\Exhibit;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Matter;
use App\Domain\Privacy\Models\DataSubjectRequest;
use App\Domain\Privacy\Models\PrivacyIncident;
use App\Domain\Tax\Models\TaxFiling;
use App\Models\User;
use App\Support\Ops\OpsAlert;
use App\Support\Tenancy\DatabaseTenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant context per request (and per queued job under Octane-style workers).
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(DeadlineCalculator::class);

        $this->app->bind(VirusScanner::class, fn () => config('services.clamav.enabled')
            ? new ClamAvScanner(config('services.clamav.host'), config('services.clamav.port'), config('services.clamav.timeout'))
            : new NullVirusScanner);
    }

    public function boot(): void
    {
        // Surface lazy loading, silently discarded attributes and missing
        // attributes as errors outside production.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Stable names in polymorphic columns (audit_logs) instead of class names.
        Relation::enforceMorphMap([
            'user' => User::class,
            'client' => Client::class,
            'matter' => Matter::class,
            'document' => Document::class,
            'deadline' => MatterDeadline::class,
            'invoice' => Invoice::class,
            'matter_file' => MatterFile::class,
            'signature_request' => SignatureRequest::class,
            'payment' => Payment::class,
            'expense' => Expense::class,
            'invoice_payment' => InvoicePayment::class,
            'data_subject_request' => DataSubjectRequest::class,
            'privacy_incident' => PrivacyIncident::class,
            'document_request' => DocumentRequest::class,
            'tax_filing' => TaxFiling::class,
            'corporate_profile' => CorporateProfile::class,
            'corporate_obligation' => CorporateObligation::class,
            'exhibit' => Exhibit::class,
            'disbursement_request' => DisbursementRequest::class,
        ]);

        $this->configureDatabaseTenancy();

        // Behind the TLS proxy PHP sees plain HTTP; links and redirects must still be https.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // A job that has used up its retries: a reminder or OCR that will not happen.
        Queue::failing(fn (JobFailed $event) => OpsAlert::send(
            'job-failed:'.$event->job->resolveName(),
            'Background job failed: '.class_basename($event->job->resolveName()),
            "Queue: {$event->job->getQueue()}\nError: ".$event->exception->getMessage()."\n\nInspect and retry with: php artisan queue:failed / queue:retry",
        ));

        // Password reset emails link to the SPA, not to a Laravel route.
        ResetPassword::createUrlUsing(fn (User $user, string $token) => rtrim(config('app.frontend_url'), '/')
            .'/reset-password?token='.$token.'&email='.urlencode($user->getEmailForPasswordReset()));

        JsonResource::withoutWrapping();

        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->mixedCase()->numbers()->uncompromised()
            : Password::min(8));

        $this->configureRateLimiting();
        $this->configureAuthorization();
    }

    /**
     * Every new PostgreSQL connection starts in the right row-level-security
     * mode: restricted to the request's firm if one is already in context
     * (a reconnect mid-request), otherwise trusted system mode. Requests
     * then switch modes in SetTenantContext.
     *
     * Console processes (queue workers, the scheduler) always start trusted:
     * a worker's connection outlives the job that happened to open it.
     */
    private function configureDatabaseTenancy(): void
    {
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) {
            $context = $this->app->make(TenantContext::class);
            $firmId = ! $this->app->runningInConsole() && $context->hasFirm() ? $context->firmId() : null;

            $this->app->make(DatabaseTenancy::class)->initialize($event->connection, $firmId);
        });
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        // Every assistant question is a billed API call.
        RateLimiter::for('assistant', fn (Request $request) => Limit::perMinute(10)->by('assistant|'.$request->user()?->id));

        // Public consultation requests: a few per hour from one address.
        RateLimiter::for('intake', fn (Request $request) => Limit::perHour(5)->by($request->ip()));

        // Brute-force protection: per account and per IP.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);
    }

    /**
     * Role-based abilities. Tenant isolation is handled separately by the
     * TenantScope, so these only answer "may this role do this?".
     */
    private function configureAuthorization(): void
    {
        Gate::define('manage-firm', fn (User $user) => $user->role->canManageFirm());
        Gate::define('manage-finances', fn (User $user) => $user->role->canManageFinances());
        Gate::define('work-matters', fn (User $user) => $user->role->canWorkMatters());
        Gate::define('practice-law', fn (User $user) => $user->role->isLawyer());

        // Expenses from a liquidated cash advance must keep matching it (and the trust ledger).
        Gate::define('modify-expense', fn (User $user, Expense $expense) => ! $expense->isInvoiced() && $expense->disbursement_request_id === null
            && ((int) $expense->user_id === $user->id || $user->role->canManageFinances()));

        Gate::define('modify-time-entry', fn (User $user, TimeEntry $entry) => ! $entry->isInvoiced()
            && ((int) $entry->user_id === $user->id || $user->role->canManageFinances()));

        Gate::define('modify-mcle-credit', fn (User $user, McleCredit $credit) => (int) $credit->user_id === $user->id
            || ($user->role->canManageFirm() && $credit->loadMissing('lawyer')->lawyer?->firm_id == $user->firm_id));

        Gate::define('delete-user', fn (User $user, User $target) => $user->role->canManageFirm() && $user->id !== $target->id);
    }
}
