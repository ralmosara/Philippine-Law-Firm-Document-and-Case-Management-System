<?php

use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AssistantController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CalendarFeedController;
use App\Http\Controllers\Api\V1\ClientAuthController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ConflictCheckController;
use App\Http\Controllers\Api\V1\DeadlineRuleController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DocumentTemplateController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\FirmController;
use App\Http\Controllers\Api\V1\HolidayController;
use App\Http\Controllers\Api\V1\IntakeController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\MatterController;
use App\Http\Controllers\Api\V1\MatterDeadlineController;
use App\Http\Controllers\Api\V1\MatterFileController;
use App\Http\Controllers\Api\V1\MatterPartyController;
use App\Http\Controllers\Api\V1\McleController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\NotarialEntryController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SignatureRequestController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TimeEntryController;
use App\Http\Controllers\Api\V1\TrustAccountController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WorkflowTemplateController;
use App\Http\Controllers\ClientPortalController;
use App\Http\Controllers\PayMongoWebhookController;
use App\Http\Controllers\PortalMessageController;
use App\Http\Controllers\PublicIntakeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Firm staff API (v1)
|--------------------------------------------------------------------------
| Authenticated with Sanctum SPA sessions. Every route below `tenant` runs
| with the user's firm as the tenant context.
*/

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::middleware('throttle:login')->group(function () {
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::post('auth/two-factor-challenge', [AuthController::class, 'twoFactorChallenge']);
        Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);
    });

    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::put('auth/password', [AuthController::class, 'updatePassword']);
        Route::post('auth/two-factor', [TwoFactorController::class, 'enable']);
        Route::post('auth/two-factor/confirm', [TwoFactorController::class, 'confirm']);
        Route::delete('auth/two-factor', [TwoFactorController::class, 'disable']);
        Route::post('auth/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes']);

        Route::get('lookups', LookupController::class);
        Route::get('firm', [FirmController::class, 'show']);
        Route::put('firm', [FirmController::class, 'update']);
        Route::get('analytics/dashboard', [AnalyticsController::class, 'dashboard']);
        Route::get('audit-logs', [AuditLogController::class, 'index']);

        Route::apiResource('users', UserController::class);
        Route::delete('users/{user}/two-factor', [TwoFactorController::class, 'reset']);

        Route::get('clients/options', [ClientController::class, 'options']);
        Route::apiResource('clients', ClientController::class);
        Route::put('clients/{client}/portal-access', [ClientController::class, 'portalAccess']);

        Route::get('matters/options', [MatterController::class, 'options']);
        Route::apiResource('matters', MatterController::class);
        Route::post('matters/{matter}/status', [MatterController::class, 'transition']);
        Route::get('matters/{matter}/timeline', [MatterController::class, 'timeline']);
        Route::apiResource('matters.parties', MatterPartyController::class)->only(['store', 'update', 'destroy'])->scoped();
        Route::get('matters/{matter}/deadlines', [MatterDeadlineController::class, 'forMatter']);
        Route::post('matters/{matter}/deadlines', [MatterDeadlineController::class, 'store']);
        Route::get('matters/{matter}/files', [MatterFileController::class, 'index']);
        Route::post('matters/{matter}/files', [MatterFileController::class, 'store']);
        Route::get('files', [MatterFileController::class, 'search']);
        Route::get('files/{file}/download', [MatterFileController::class, 'download']);
        Route::patch('files/{file}', [MatterFileController::class, 'update']);
        Route::delete('files/{file}', [MatterFileController::class, 'destroy']);

        Route::get('message-threads/unread-count', [MessageController::class, 'unreadCount']);
        Route::get('message-threads', [MessageController::class, 'index']);
        Route::post('message-threads', [MessageController::class, 'store']);
        Route::get('message-threads/{thread}', [MessageController::class, 'show']);
        Route::post('message-threads/{thread}/messages', [MessageController::class, 'reply']);

        Route::get('assistant/status', [AssistantController::class, 'status']);
        Route::get('matters/{matter}/assistant', [AssistantController::class, 'index']);
        Route::post('matters/{matter}/assistant', [AssistantController::class, 'ask'])->middleware('throttle:assistant');
        Route::get('assistant/conversations/{conversation}', [AssistantController::class, 'show'])->whereNumber('conversation');
        Route::post('assistant/messages/{message}/retry', [AssistantController::class, 'retry'])->whereNumber('message')->middleware('throttle:assistant');
        Route::post('assistant/messages/{message}/document', [AssistantController::class, 'saveAsDocument'])->whereNumber('message');

        Route::get('intake-requests', [IntakeController::class, 'index']);
        Route::get('intake-requests/{intakeRequest}', [IntakeController::class, 'show']);
        Route::post('intake-requests/{intakeRequest}/schedule', [IntakeController::class, 'schedule']);
        Route::post('intake-requests/{intakeRequest}/decline', [IntakeController::class, 'decline']);
        Route::post('intake-requests/{intakeRequest}/accept', [IntakeController::class, 'accept']);

        Route::get('calendar-feed', [CalendarFeedController::class, 'show']);
        Route::post('calendar-feed', [CalendarFeedController::class, 'store']);
        Route::delete('calendar-feed', [CalendarFeedController::class, 'destroy']);

        Route::get('tasks', [TaskController::class, 'index']);
        Route::post('tasks/{deadline}/move', [TaskController::class, 'move']);

        Route::get('deadlines', [MatterDeadlineController::class, 'index']);
        Route::post('deadlines/compute', [MatterDeadlineController::class, 'compute']);
        Route::get('deadlines/{deadline}', [MatterDeadlineController::class, 'show']);
        Route::patch('deadlines/{deadline}', [MatterDeadlineController::class, 'update']);
        Route::post('deadlines/{deadline}/complete', [MatterDeadlineController::class, 'complete']);
        Route::post('deadlines/{deadline}/cancel', [MatterDeadlineController::class, 'cancel']);
        Route::post('deadlines/{deadline}/reschedule', [MatterDeadlineController::class, 'reschedule']);

        Route::apiResource('deadline-rules', DeadlineRuleController::class)->except('show')->parameters(['deadline-rules' => 'rule']);
        Route::apiResource('holidays', HolidayController::class)->only(['index', 'store', 'destroy']);
        Route::apiResource('workflow-templates', WorkflowTemplateController::class)->except('show');

        Route::apiResource('document-templates', DocumentTemplateController::class);
        Route::apiResource('documents', DocumentController::class);
        Route::get('documents/{document}/pdf', [DocumentController::class, 'pdf']);
        Route::get('documents/{document}/versions', [DocumentController::class, 'versions']);
        Route::post('documents/{document}/versions', [DocumentController::class, 'saveVersion']);
        Route::post('documents/{document}/status', [DocumentController::class, 'transition']);
        Route::get('documents/{document}/signature-requests', [SignatureRequestController::class, 'index']);
        Route::post('documents/{document}/signature-requests', [SignatureRequestController::class, 'store']);
        Route::post('signature-requests/{signatureRequest}/cancel', [SignatureRequestController::class, 'cancel']);

        Route::get('notarial-entries/next', [NotarialEntryController::class, 'next']);
        Route::apiResource('notarial-entries', NotarialEntryController::class)->only(['index', 'store']);

        Route::apiResource('trust-accounts', TrustAccountController::class)->only(['index', 'store', 'show']);
        Route::get('trust-accounts/{trustAccount}/transactions', [TrustAccountController::class, 'transactions']);
        Route::post('trust-accounts/{trustAccount}/transactions', [TrustAccountController::class, 'record']);
        Route::get('trust-accounts/{trustAccount}/reconcile', [TrustAccountController::class, 'reconcile']);
        Route::post('trust-accounts/{trustAccount}/close', [TrustAccountController::class, 'close']);

        Route::apiResource('time-entries', TimeEntryController::class)->except('show');
        Route::apiResource('expenses', ExpenseController::class)->except('show');

        Route::apiResource('invoices', InvoiceController::class)->only(['index', 'store', 'show']);
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf']);
        Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue']);
        Route::get('reports/{report}', [ReportController::class, 'show'])->whereIn('report', ['aged-receivables', 'collections', 'matter-profitability']);
        Route::post('invoices/{invoice}/pay', [InvoiceController::class, 'pay']);
        Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void']);
        Route::post('invoices/{invoice}/payment-link', [InvoiceController::class, 'paymentLink']);

        Route::apiResource('conflict-checks', ConflictCheckController::class)->only(['index', 'store', 'show']);
        Route::post('conflict-checks/{conflictCheck}/resolve', [ConflictCheckController::class, 'resolve']);

        Route::get('mcle/periods', [McleController::class, 'periods']);
        Route::post('mcle/periods', [McleController::class, 'storePeriod']);
        Route::get('mcle/status', [McleController::class, 'status']);
        Route::get('mcle/firm', [McleController::class, 'firm']);
        Route::post('mcle/credits', [McleController::class, 'storeCredit']);
        Route::delete('mcle/credits/{credit}', [McleController::class, 'destroyCredit']);
    });
});

/*
|--------------------------------------------------------------------------
| Client portal API
|--------------------------------------------------------------------------
| A separate session guard (`client`) and a read-only surface.
*/

Route::prefix('portal')->middleware('throttle:api')->group(function () {
    Route::middleware('throttle:login')->group(function () {
        Route::post('login', [ClientAuthController::class, 'login']);
        Route::post('forgot-password', [ClientAuthController::class, 'forgotPassword']);
        Route::post('reset-password', [ClientAuthController::class, 'resetPassword']);
    });

    Route::middleware(['auth:client', 'tenant:client'])->group(function () {
        Route::get('me', [ClientAuthController::class, 'me']);
        Route::post('logout', [ClientAuthController::class, 'logout']);
        Route::get('matters', [ClientPortalController::class, 'getMatters']);
        Route::get('matters/{matter}', [ClientPortalController::class, 'getMatter'])->whereNumber('matter');
        Route::get('documents/{document}', [ClientPortalController::class, 'getDocument'])->whereNumber('document');
        Route::get('documents/{document}/pdf', [ClientPortalController::class, 'getDocumentPdf'])->whereNumber('document');
        Route::get('invoices/{invoice}/pdf', [ClientPortalController::class, 'getInvoicePdf'])->whereNumber('invoice');
        Route::get('invoices', [ClientPortalController::class, 'getInvoices']);
        Route::post('invoices/{invoice}/checkout', [ClientPortalController::class, 'checkout'])->whereNumber('invoice');
        Route::get('trust-accounts', [ClientPortalController::class, 'getTrustAccounts']);
        Route::get('files/{file}/download', [ClientPortalController::class, 'downloadFile'])->whereNumber('file');
        Route::get('message-threads', [PortalMessageController::class, 'index']);
        Route::post('message-threads', [PortalMessageController::class, 'store']);
        Route::get('message-threads/{thread}', [PortalMessageController::class, 'show'])->whereNumber('thread');
        Route::post('message-threads/{thread}/messages', [PortalMessageController::class, 'reply'])->whereNumber('thread');
        Route::get('signature-requests', [ClientPortalController::class, 'getSignatureRequests']);
        Route::get('signature-requests/{signatureRequest}', [ClientPortalController::class, 'getSignatureRequest'])->whereNumber('signatureRequest');
        Route::post('signature-requests/{signatureRequest}/sign', [ClientPortalController::class, 'signDocument'])->whereNumber('signatureRequest');
        Route::post('signature-requests/{signatureRequest}/decline', [ClientPortalController::class, 'declineSignature'])->whereNumber('signatureRequest');
    });
});

/*
|--------------------------------------------------------------------------
| Provider webhooks
|--------------------------------------------------------------------------
| No session or user; each request is authenticated by its signature.
*/

Route::post('webhooks/paymongo', PayMongoWebhookController::class)->middleware('throttle:api');

// A firm's public consultation-request form.
Route::get('public/intake/{slug}', [PublicIntakeController::class, 'show'])->middleware('throttle:api');
Route::post('public/intake/{slug}', [PublicIntakeController::class, 'submit'])->middleware('throttle:intake');

// Calendar subscriptions: calendar apps cannot sign in, so the unguessable
// token in the URL authenticates the request.
Route::get('calendar/{token}.ics', [CalendarFeedController::class, 'feed'])
    ->where('token', '[A-Za-z0-9]{40}')
    ->middleware('throttle:60,1');
