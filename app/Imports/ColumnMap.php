<?php

namespace App\Imports;

use Illuminate\Support\Str;

/** Which spreadsheet column (0-based) holds each field. */
final readonly class ColumnMap
{
    public const FIELDS = ['date', 'description', 'amount', 'card', 'cardholder', 'purpose'];

    public const REQUIRED = ['date', 'description', 'amount'];

    /** Header words that suggest each field, checked in this order. */
    private const HINTS = [
        'date' => ['date'],
        'description' => ['description', 'merchant', 'details', 'narrative'],
        'amount' => ['amount', 'value'],
        'card' => ['account', 'card number', 'card no', 'card #'],
        'cardholder' => ['card member', 'cardholder', 'card holder', 'name'],
        'purpose' => ['purpose', 'what was it for', 'reason'],
    ];

    public function __construct(
        public int $date,
        public int $description,
        public int $amount,
        public ?int $card = null,
        public ?int $cardholder = null,
        public ?int $purpose = null,
    ) {}

    public static function fromArray(array $mapping): self
    {
        $optional = fn (string $key) => isset($mapping[$key]) && $mapping[$key] !== '' ? (int) $mapping[$key] : null;

        return new self(
            (int) $mapping['date'],
            (int) $mapping['description'],
            (int) $mapping['amount'],
            $optional('card'),
            $optional('cardholder'),
            $optional('purpose'),
        );
    }

    /** @return array<string, ?int> */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'description' => $this->description,
            'amount' => $this->amount,
            'card' => $this->card,
            'cardholder' => $this->cardholder,
            'purpose' => $this->purpose,
        ];
    }

    /** @return list<int> */
    public function mappedColumns(): array
    {
        return array_values(array_filter(
            [$this->date, $this->description, $this->amount, $this->card, $this->cardholder, $this->purpose],
            fn (?int $column) => $column !== null,
        ));
    }

    /**
     * Suggest a mapping from header labels. Each column is used at most once.
     *
     * @return array<string, ?int>
     */
    public static function guess(array $headers): array
    {
        $labels = array_map(fn ($header) => Str::lower(Str::squish((string) $header)), $headers);
        $guess = array_fill_keys(self::FIELDS, null);
        $used = [];

        foreach (self::HINTS as $field => $hints) {
            foreach ($labels as $index => $label) {
                if (in_array($index, $used, true) || $label === '') {
                    continue;
                }

                if (Str::contains($label, $hints)) {
                    $guess[$field] = $index;
                    $used[] = $index;
                    break;
                }
            }
        }

        return $guess;
    }
}
