<?php

namespace App\Http\Controllers;

use App\Domain\Billing\Payments\OnlinePayments;
use App\Domain\Billing\Payments\PayMongoGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives PayMongo webhooks. Unauthenticated by design; every request must
 * carry a valid HMAC signature made with the webhook's secret.
 */
class PayMongoWebhookController extends Controller
{
    public function __invoke(Request $request, PayMongoGateway $gateway, OnlinePayments $payments): JsonResponse
    {
        if (! $gateway->hasValidSignature($request->getContent(), $request->header('Paymongo-Signature'))) {
            return response()->json(['status' => 'error', 'message' => 'Invalid signature.'], 400);
        }

        $payments->handleEvent($request->json()->all());

        return response()->json(['received' => true]);
    }
}
