<?php

namespace App\Console\Commands;

use App\Mail\Inbound\EmlMailSource;
use App\Mail\Inbound\InboundMailSource;
use App\Mail\Inbound\ReceiptIngestor;
use Illuminate\Console\Command;
use Throwable;

class IngestReceipts extends Command
{
    protected $signature = 'receipts:ingest {path? : An .eml file or a folder of them (uses the eml driver)}';

    protected $description = 'Turn emails sent to the receipts address into inbox receipts';

    public function handle(ReceiptIngestor $ingestor): int
    {
        $path = $this->argument('path');
        $driver = $path ? 'eml' : (string) config('cardtrail.inbound.driver');

        if (! $path && $driver === 'eml' && ! config('cardtrail.inbound.eml_path')) {
            $this->error('Give a path to an .eml file or folder, or set CARDTRAIL_INBOUND_EML_PATH.');

            return self::FAILURE;
        }

        $source = $path ? new EmlMailSource($path) : app(InboundMailSource::class);
        $counts = [];

        try {
            foreach ($source->fetch() as $email) {
                try {
                    $message = $ingestor->ingest($email, $driver);
                } catch (Throwable $e) {
                    report($e);
                    $this->error("Couldn’t process {$email->messageId}: {$e->getMessage()}");

                    continue;
                }

                $source->markProcessed($email);
                $label = $message ? str_replace('_', ' ', $message->outcome->value) : 'already processed';
                $counts[$label] = ($counts[$label] ?? 0) + 1;
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($counts === []) {
            $this->info('No emails to process.');
        }

        foreach ($counts as $label => $count) {
            $this->line("{$label}: {$count}");
        }

        return self::SUCCESS;
    }
}
