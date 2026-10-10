<?php

use App\Enums\ImportStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Events\ExportFailed;
use App\Events\ExportReady;
use App\Events\ReconciliationLinesAdded;
use App\Models\Export;
use App\Models\Import;
use App\Models\Period;
use App\Models\User;
use App\Notifications\ExportFailedNotification;
use App\Notifications\ExportReadyNotification;
use App\Notifications\LineQueriedNotification;
use App\Notifications\LinesAdded;
use App\Notifications\MonthReady;
use App\Notifications\Nudge;
use App\Notifications\ReconciliationSubmittedNotification;
use App\Reconciliations\ReconciliationSubmitter;
use App\Reviews\LineReviewer;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->admin = User::factory()->admin()->create(['name' => 'Alex Accounts', 'is_cardholder' => false]);
    $this->sam = User::factory()->create(['name' => 'Sam Cardholder']);
});

it('tells each active cardholder with lines when a month is published', function () {
    $period = Period::factory()->create(['label' => 'September 2026']);
    Import::factory()->for($period)->create(['status' => ImportStatus::Confirmed]);
    $leaver = User::factory()->inactive()->create();
    lineFor($this->sam, [], $period);
    lineFor($leaver, [], $period);

    $this->actingAs($this->admin)->post(route('admin.periods.publish', $period));

    Notification::assertSentTo($this->sam, MonthReady::class, function (MonthReady $notification) use ($period) {
        $mail = $notification->toMail($this->sam);

        return $notification->reconciliation->period->is($period)
            && $mail->subject === 'September 2026: your card transactions are ready'
            && $mail->actionUrl === route('reconciliations.show', $notification->reconciliation);
    });
    Notification::assertNotSentTo($leaver, MonthReady::class);
});

it('tells a cardholder when more lines land in a published month', function () {
    $line = lineFor($this->sam);

    ReconciliationLinesAdded::dispatch($line->reconciliation, 2);

    Notification::assertSentTo($this->sam, LinesAdded::class, fn (LinesAdded $notification) => $notification->count === 2);
});

it('tells the cardholder when accounts query a line', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Done, 'purpose' => 'Lunch', 'description' => 'DEPOT CAFE']);
    $line->reconciliation->update(['status' => ReconciliationStatus::Submitted, 'submitted_at' => now()]);

    app(LineReviewer::class)->query($line->fresh(), $this->admin, 'Who attended?');

    Notification::assertSentTo($this->sam, LineQueriedNotification::class, function (LineQueriedNotification $notification) use ($line) {
        $push = $notification->toWebPush($this->sam, $notification)->toArray();

        return $push['title'] === 'Accounts have a question about DEPOT CAFE'
            && $push['body'] === '“Who attended?”'
            && $push['data']['url'] === route('transactions.show', $line);
    });
});

it('tells every other active admin when a month is submitted', function () {
    $otherAdmin = User::factory()->admin()->create();
    $inactiveAdmin = User::factory()->admin()->inactive()->create();
    $adminCardholder = User::factory()->admin()->create();
    $line = lineFor($adminCardholder, ['status' => TransactionStatus::Done]);

    app(ReconciliationSubmitter::class)->submit($line->reconciliation, $adminCardholder);

    Notification::assertSentTo([$this->admin, $otherAdmin], ReconciliationSubmittedNotification::class, function (ReconciliationSubmittedNotification $notification) {
        return ! $notification->resubmitted
            && $notification->toMail($this->admin)->actionUrl === route('admin.reconciliations.review', $notification->reconciliation);
    });
    Notification::assertNotSentTo([$inactiveAdmin, $adminCardholder], ReconciliationSubmittedNotification::class);
});

it('says when a month was resubmitted after a query', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Done]);
    $line->reconciliation->update(['submitted_at' => now()->subDay()]);

    app(ReconciliationSubmitter::class)->submit($line->reconciliation, $this->sam);

    Notification::assertSentTo($this->admin, ReconciliationSubmittedNotification::class, function (ReconciliationSubmittedNotification $notification) {
        return $notification->toMail($this->admin)->subject === 'Sam Cardholder resubmitted '.$notification->reconciliation->period->label;
    });
});

it('tells the admin who asked when an export is ready or has failed', function () {
    $export = Export::factory()->create(['requested_by' => $this->admin->id, 'filename' => 'September 2026.zip']);

    ExportReady::dispatch($export);
    $export->update(['error' => 'The receipt for row 2 is missing from storage.']);
    ExportFailed::dispatch($export);

    Notification::assertSentTo($this->admin, ExportReadyNotification::class);
    Notification::assertSentTo($this->admin, ExportFailedNotification::class, function (ExportFailedNotification $notification) {
        return in_array('The receipt for row 2 is missing from storage.', $notification->toMail($this->admin)->introLines, true);
    });
});

it('drops a queued notification if the person was deactivated before it went out', function () {
    $line = lineFor($this->sam);
    $notification = new Nudge($line->reconciliation, $this->admin);
    $this->sam->update(['active' => false]);

    $this->sam->notify($notification);

    Notification::assertNotSentTo($this->sam, Nudge::class);
});
