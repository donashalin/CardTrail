<?php

namespace App\Imports;

use App\Models\Import;
use App\Models\Transaction;
use LogicException;

/** Shows exactly what confirming an import would do, row by row. */
final class ImportPreviewer
{
    public function __construct(private ImportSheet $sheet) {}

    /** @param  array<int, array{date?: ?string, amount?: ?string, description?: ?string}>  $fixes  keyed by row number */
    public function preview(Import $import, array $fixes = []): ImportPreview
    {
        if ($import->column_mapping === null) {
            throw new LogicException('The import has no column mapping yet.');
        }

        $parser = new RowParser(
            ColumnMap::fromArray($import->column_mapping),
            $this->sheet->headers($import, $import->header_row),
        );
        $matcher = CardholderMatcher::forActiveCardholders();
        $seen = $this->keysAlreadyInPeriod($import);

        $rows = [];
        foreach ($this->sheet->dataRows($import, $import->header_row) as $rowNumber => $cells) {
            $parsed = $parser->parse($rowNumber, $cells, $fixes[$rowNumber] ?? []);
            $key = $parsed->duplicateKey();
            $duplicate = $key !== null && isset($seen[$key]);

            if ($key !== null) {
                $seen[$key] = true;
            }

            $rows[] = new PreviewRow(
                $parsed,
                $parsed->isValid() ? $matcher->match($parsed->last4, $parsed->cardholderName) : null,
                $duplicate,
            );
        }

        return new ImportPreview($rows);
    }

    /** @return array<string, true> */
    private function keysAlreadyInPeriod(Import $import): array
    {
        return Transaction::query()
            ->where('period_id', $import->period_id)
            ->where('import_id', '!=', $import->id)
            ->get(['transacted_on', 'amount', 'description', 'card_ref'])
            ->mapWithKeys(fn (Transaction $transaction) => [
                DuplicateKey::make($transaction->transacted_on, $transaction->amount, $transaction->description, $transaction->card_ref) => true,
            ])
            ->all();
    }
}
