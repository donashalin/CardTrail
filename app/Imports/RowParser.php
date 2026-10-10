<?php

namespace App\Imports;

use App\Imports\Parsing\AmountParser;
use App\Imports\Parsing\CardNumberMasker;
use App\Imports\Parsing\DateParser;
use DateTimeInterface;
use Illuminate\Support\Str;

final class RowParser
{
    /** @param  array<int, string>  $headers  header labels by column index */
    public function __construct(
        private ColumnMap $map,
        private array $headers,
    ) {}

    /**
     * @param  list<mixed>  $cells
     * @param  array{date?: ?string, amount?: ?string, description?: ?string}  $overrides  the admin's corrections
     */
    public function parse(int $rowNumber, array $cells, array $overrides = []): ParsedRow
    {
        $rawDate = $overrides['date'] ?? $cells[$this->map->date] ?? null;
        $rawAmount = $overrides['amount'] ?? $cells[$this->map->amount] ?? null;
        $description = Str::limit(
            CardNumberMasker::mask(self::text($overrides['description'] ?? $cells[$this->map->description] ?? null)),
            500,
            '',
        );

        $date = DateParser::parse($rawDate);
        $amount = AmountParser::parse($rawAmount);

        $errors = [];
        if ($date === null) {
            $errors['date'] = "Couldn't read this date.";
        }
        if ($amount === null) {
            $errors['amount'] = "Couldn't read this amount.";
        }
        if ($description === '') {
            $errors['description'] = 'The description is missing.';
        }

        $last4 = $this->map->card !== null ? CardNumberMasker::last4($cells[$this->map->card] ?? null) : null;
        $name = $this->map->cardholder !== null ? self::text($cells[$this->map->cardholder] ?? null) : '';

        return new ParsedRow(
            rowNumber: $rowNumber,
            date: $date,
            rawDate: self::text($rawDate),
            description: $description,
            amount: $amount,
            rawAmount: self::text($rawAmount),
            last4: $last4,
            cardholderName: $name !== '' ? $name : null,
            extra: $this->extra($cells),
            errors: $errors,
        );
    }

    /** Any cell value as trimmed display text. Whole-number floats lose their ".0". */
    public static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_float($value) && floor($value) === $value => (string) (int) $value,
            default => trim((string) $value),
        };
    }

    /** @return array<string, string> */
    private function extra(array $cells): array
    {
        $mapped = $this->map->mappedColumns();
        $extra = [];

        foreach ($cells as $index => $value) {
            $text = self::text($value);

            if ($text === '' || in_array($index, $mapped, true)) {
                continue;
            }

            $label = trim((string) ($this->headers[$index] ?? ''));
            $extra[$label !== '' ? $label : 'Column '.($index + 1)] = CardNumberMasker::mask($text);
        }

        return $extra;
    }
}
