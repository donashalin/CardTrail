<?php

namespace App\Imports;

final readonly class PreviewRow
{
    public function __construct(
        public ParsedRow $parsed,
        public ?int $userId,
        public bool $duplicate,
    ) {}

    /** @param  array<int, string>  $userNames */
    public function toArray(array $userNames): array
    {
        return [
            'row_number' => $this->parsed->rowNumber,
            'date' => $this->parsed->date?->format('Y-m-d'),
            'raw_date' => $this->parsed->rawDate,
            'description' => $this->parsed->description,
            'amount' => $this->parsed->amount,
            'raw_amount' => $this->parsed->rawAmount,
            'card_ref' => $this->parsed->cardRef(),
            'user_id' => $this->userId,
            'user_name' => $this->userId !== null ? ($userNames[$this->userId] ?? null) : null,
            'duplicate' => $this->duplicate,
            'errors' => $this->parsed->errors,
            'extra' => $this->parsed->extra,
        ];
    }
}
