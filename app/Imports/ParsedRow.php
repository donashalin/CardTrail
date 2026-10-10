<?php

namespace App\Imports;

use Carbon\CarbonImmutable;

final readonly class ParsedRow
{
    /**
     * @param  array<string, string>  $extra  unmapped columns, label => text (card numbers already masked)
     * @param  array<string, string>  $errors  field => message
     */
    public function __construct(
        public int $rowNumber,
        public ?CarbonImmutable $date,
        public string $rawDate,
        public string $description,
        public ?int $amount,
        public string $rawAmount,
        public ?string $last4,
        public ?string $cardholderName,
        public array $extra,
        public array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function cardRef(): ?string
    {
        return $this->last4 ?? $this->cardholderName;
    }

    public function duplicateKey(): ?string
    {
        return $this->isValid()
            ? DuplicateKey::make($this->date, $this->amount, $this->description, $this->cardRef())
            : null;
    }
}
