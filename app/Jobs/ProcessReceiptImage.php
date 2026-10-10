<?php

namespace App\Jobs;

use App\Models\Receipt;
use App\Receipts\ImageOptimiser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessReceiptImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** The receipt may be deleted before the job runs. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Receipt $receipt) {}

    public function handle(ImageOptimiser $optimiser): void
    {
        $optimiser->optimise($this->receipt);
    }
}
