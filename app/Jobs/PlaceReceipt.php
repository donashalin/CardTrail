<?php

namespace App\Jobs;

use App\Models\Receipt;
use App\Receipts\ReceiptFiler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;

/** Moves a receipt's file to its folder after its line, its owner's name or its period's month changes. */
class PlaceReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    /** The receipt may be deleted before the job runs. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Receipt $receipt) {}

    /** @param  Builder<Receipt>  $receipts */
    public static function dispatchFor(Builder $receipts): void
    {
        $receipts->each(fn (Receipt $receipt) => static::dispatch($receipt)->afterCommit());
    }

    public function handle(ReceiptFiler $filer): void
    {
        $filer->locked(fn () => $filer->place($this->receipt));
    }
}
