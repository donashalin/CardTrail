<?php

use App\Enums\TransactionStatus;
use App\Models\Comment;
use App\Models\Receipt;
use App\Models\User;
use App\Periods\TransactionAssigner;

it('sends receipts back to the previous owner’s inbox and drops the thread when a line moves', function () {
    $sam = User::factory()->create();
    $jo = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $line = lineFor($sam, ['purpose' => 'Hotel', 'status' => TransactionStatus::Done]);
    $receipt = Receipt::factory()->attachedTo($line)->create();
    Comment::factory()->for($line)->for($admin)->create();

    app(TransactionAssigner::class)->assign($line->period, [$line->id], $jo, $admin);

    expect($receipt->fresh())
        ->transaction_id->toBeNull()
        ->user_id->toBe($sam->id)
        ->and($line->comments()->count())->toBe(0)
        ->and($line->fresh()->status)->toBe(TransactionStatus::Todo);
});
