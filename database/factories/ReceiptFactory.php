<?php

namespace Database\Factories;

use App\Enums\ReceiptSource;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Receipt>
 */
class ReceiptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'transaction_id' => null,
            'disk' => 'receipts',
            'path' => fn () => 'demo/'.Str::uuid().'.pdf',
            'original_name' => 'receipt.pdf',
            'mime' => 'application/pdf',
            'size' => 2048,
            'source' => ReceiptSource::Upload,
        ];
    }

    /** On this line, owned by the line's cardholder. */
    public function attachedTo(Transaction $line): static
    {
        return $this->state(fn () => ['transaction_id' => $line->id, 'user_id' => $line->reconciliation->user_id]);
    }
}
