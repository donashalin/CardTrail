<?php

use App\Enums\InboundOutcome;
use App\Models\InboundMessage;
use App\Models\User;

it('shows admins what happened to each inbound email, newest first', function () {
    $sam = User::factory()->create(['name' => 'Sam Cardholder']);
    InboundMessage::create(['source_driver' => 'eml', 'message_id' => 'a@x', 'from_email' => 'stranger@example.test', 'subject' => 'Hello', 'outcome' => InboundOutcome::UnknownSender]);
    InboundMessage::create(['source_driver' => 'eml', 'message_id' => 'b@x', 'from_email' => 'sam@cardtrail.test', 'subject' => 'Taxi', 'user_id' => $sam->id, 'outcome' => InboundOutcome::Accepted, 'attachment_count' => 2, 'accepted_count' => 1]);

    $this->actingAs(User::factory()->admin()->create())->get(route('admin.inbound.index'))
        ->assertInertia(fn ($page) => $page
            ->component('admin/inbound/Index')
            ->has('messages.data', 2)
            ->where('messages.data.0.subject', 'Taxi')
            ->where('messages.data.0.user', 'Sam Cardholder')
            ->where('messages.data.0.outcome', 'accepted')
            ->where('messages.data.0.accepted_count', 1)
            ->where('messages.data.1.outcome', 'unknown_sender'));
});

it('keeps the inbound log to admins', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.inbound.index'))->assertForbidden();
});
