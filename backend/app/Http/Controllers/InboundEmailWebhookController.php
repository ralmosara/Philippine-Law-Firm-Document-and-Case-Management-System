<?php

namespace App\Http\Controllers;

use App\Domain\Correspondence\InboundEmails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives mail for matter addresses from the inbound email provider.
 * Unauthenticated by design; each request must carry the shared secret, as
 * the basic-auth password (https://inbound:<secret>@your-domain/...) or the
 * X-Inbound-Secret header. Never in the query string: web server logs
 * record full URLs.
 *
 * Accepts the raw message as the request body (message/rfc822), or as the
 * "email" field (SendGrid Inbound Parse, "POST the raw, full MIME message")
 * or "body-mime" field (Mailgun routes to a .../mime URL).
 */
class InboundEmailWebhookController extends Controller
{
    public function __invoke(Request $request, InboundEmails $emails): JsonResponse
    {
        if (! InboundEmails::enabled()) {
            abort(404);
        }
        $given = $request->header('X-Inbound-Secret') ?? $request->getPassword();
        if (! InboundEmails::authorized(is_string($given) ? $given : null)) {
            return response()->json(['message' => 'Invalid secret.'], 401);
        }

        $raw = $request->input('email') ?? $request->input('body-mime') ?? $request->getContent();
        if (! is_string($raw) || trim($raw) === '') {
            return response()->json(['message' => 'No message in the request.'], 422);
        }
        if (strlen($raw) > config('services.inbound_email.max_kilobytes') * 1024) {
            return response()->json(['message' => 'Message too large.'], 413);
        }

        $dkim = $request->input('dkim'); // SendGrid's DKIM results
        $created = $emails->receive($raw, $this->envelopeRecipients($request), is_string($dkim) ? $dkim : null);

        // 200 even when no matter matched, so the provider does not retry it forever.
        return response()->json(['filed' => count($created)]);
    }

    /** @return list<string> */
    private function envelopeRecipients(Request $request): array
    {
        $recipients = [];
        $envelope = json_decode((string) $request->input('envelope'), true); // SendGrid: {"to": [...], "from": "..."}
        if (is_array($envelope['to'] ?? null)) {
            $recipients = array_merge($recipients, array_filter($envelope['to'], 'is_string'));
        }
        foreach (['recipient', 'to'] as $field) { // Mailgun: "recipient"
            if (is_string($request->input($field))) {
                $recipients[] = $request->input($field);
            }
        }

        return $recipients;
    }
}
