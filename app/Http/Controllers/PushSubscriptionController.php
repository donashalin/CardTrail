<?php

namespace App\Http\Controllers;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** A browser or installed app turning push notifications on or off for the signed-in user. */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'starts_with:https://', 'max:1024', function (string $attribute, mixed $value, Closure $fail) {
                // The server posts to this address, so only the browsers' own push services are allowed.
                $host = strtolower((string) parse_url((string) $value, PHP_URL_HOST));
                $allowed = collect(config('cardtrail.push_hosts'))->contains(fn (string $suffix) => $host === ltrim($suffix, '.') || str_ends_with($host, $suffix));

                if (! $allowed) {
                    $fail('That isn’t a recognised push notification service.');
                }
            }],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', Rule::in(['aesgcm', 'aes128gcm'])],
        ]);

        $request->user()->updatePushSubscription(
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $data['content_encoding'] ?? 'aes128gcm',
        );

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1024']]);

        $request->user()->deletePushSubscription($data['endpoint']);

        return response()->noContent();
    }
}
