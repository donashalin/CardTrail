<?php

namespace App\Jobs;

use App\Models\Receipt;
use App\Receipts\ImageOptimiser;
use App\Receipts\ReceiptFiler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessReceiptImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** The receipt may be deleted before the job runs. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Receipt $receipt) {}

    public function handle(ImageOptimiser $optimiser, ReceiptFiler $filer): void
    {
        $filer->locked(function () use ($optimiser, $filer) {
            // Another job may have moved the file while this one waited for the lock.
            $receipt = $this->receipt->fresh();

            if ($receipt === null) {
                return;
            }

            $optimiser->optimise($receipt);
            $filer->place($receipt);
        });
    }
}
