<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Push\Models\PushSubscription;
use App\Domain\Push\Notifications\TestPush;
use App\Domain\Push\PushSender;
use App\Domain\Push\WebPushChannel;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Turning phone and browser notifications on and off, per device. */
class PushController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'enabled' => PushSender::enabled(),
            'public_key' => config('services.webpush.public_key'),
            'devices' => PushSubscription::where('user_id', $request->user()->id)->count(),
            'push_details' => (bool) $request->user()->push_details,
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        abort_unless(PushSender::enabled(), 422, 'Phone notifications are not set up on this server.');
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000', 'url:https'],
            'keys.p256dh' => ['required', 'string', 'max:200'],
            'keys.auth' => ['required', 'string', 'max:100'],
        ]);
        if (! PushSender::allowedEndpoint($validated['endpoint'])) {
            throw ValidationException::withMessages(['endpoint' => 'This browser\'s push service is not supported.']);
        }

        $subscription = PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $validated['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $validated['endpoint'],
                'p256dh' => $validated['keys']['p256dh'],
                'auth' => $validated['keys']['auth'],
                'user_agent' => Str::limit((string) $request->userAgent(), 290),
            ],
        );

        return response()->json(['devices' => PushSubscription::where('user_id', $request->user()->id)->count()], $subscription->wasRecentlyCreated ? 201 : 200);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);
        PushSubscription::where('user_id', $request->user()->id)->where('endpoint_hash', hash('sha256', $validated['endpoint']))->delete();

        return response()->json(['devices' => PushSubscription::where('user_id', $request->user()->id)->count()]);
    }

    public function preferences(Request $request): JsonResponse
    {
        $validated = $request->validate(['push_details' => ['required', 'boolean']]);
        $request->user()->forceFill(['push_details' => $validated['push_details']])->save();

        return $this->show($request);
    }

    /** A test notification to every device the user turned on. */
    public function test(Request $request): JsonResponse
    {
        abort_unless(WebPushChannel::wanted($request->user()), 422, 'Turn on notifications on this device first.');
        $request->user()->notifyNow(new TestPush, [WebPushChannel::class]);

        return response()->json(['sent' => true]);
    }
}
