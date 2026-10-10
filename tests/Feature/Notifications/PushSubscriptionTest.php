<?php

use App\Models\User;
use App\Notifications\CardTrailNotification;
use NotificationChannels\WebPush\WebPushChannel;

function subscriptionPayload(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc'): array
{
    return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BPublicKeyInvented', 'auth' => 'AuthInvented'], 'content_encoding' => 'aes128gcm'];
}

it('stores a device’s push subscription for the signed-in user', function () {
    $sam = User::factory()->create();

    $this->actingAs($sam)->postJson(route('push-subscriptions.store'), subscriptionPayload())->assertNoContent();

    expect($sam->pushSubscriptions()->sole())
        ->endpoint->toBe('https://fcm.googleapis.com/fcm/send/abc')
        ->public_key->toBe('BPublicKeyInvented');
});

it('refuses subscriptions that are not secure push endpoints', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('push-subscriptions.store'), subscriptionPayload('http://fcm.googleapis.com/fcm/send/x'))
        ->assertUnprocessable();
});

it('removes a subscription when the device opts out', function () {
    $sam = User::factory()->create();
    $sam->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'k', 't', 'aes128gcm');

    $this->actingAs($sam)->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'])->assertNoContent();

    expect($sam->pushSubscriptions()->count())->toBe(0);
});

it('needs sign-in', function () {
    $this->postJson(route('push-subscriptions.store'), subscriptionPayload())->assertUnauthorized();
});

it('emails everyone and pushes only to people with a subscribed device', function () {
    $notification = new class extends CardTrailNotification
    {
        protected function subject(): string
        {
            return 'Test subject';
        }

        protected function lines(): array
        {
            return ['First line', 'Second line'];
        }

        protected function url(): string
        {
            return url('/dashboard');
        }
    };
    $sam = User::factory()->create(['name' => 'Sam Cardholder']);

    expect($notification->via($sam))->toBe(['mail']);

    $sam->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'k', 't', 'aes128gcm');

    expect($notification->via($sam))->toBe(['mail', WebPushChannel::class]);

    $mail = $notification->toMail($sam);
    $push = $notification->toWebPush($sam, $notification)->toArray();

    expect($mail->subject)->toBe('Test subject')
        ->and($mail->greeting)->toBe('Hello Sam,')
        ->and($mail->introLines)->toBe(['First line', 'Second line'])
        ->and($mail->actionUrl)->toBe(url('/dashboard'))
        ->and($push['title'])->toBe('Test subject')
        ->and($push['body'])->toBe('First line')
        ->and($push['data'])->toBe(['url' => url('/dashboard')]);
});

it('shares the public VAPID key with the app', function () {
    config(['webpush.vapid.public_key' => 'BInventedPublicKey']);

    $this->actingAs(User::factory()->create())->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('push.vapid_public_key', 'BInventedPublicKey'));
});

it('only accepts endpoints on the browsers’ push services', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('push-subscriptions.store'), subscriptionPayload('https://intranet.internal.test/hook'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('endpoint');

    foreach (['https://fcm.googleapis.com/fcm/send/a', 'https://updates.push.services.mozilla.com/wpush/v2/b', 'https://web.push.apple.com/c', 'https://db5p.notify.windows.com/w/?token=d'] as $endpoint) {
        $this->actingAs(User::factory()->create())->postJson(route('push-subscriptions.store'), subscriptionPayload($endpoint))->assertNoContent();
    }
});
