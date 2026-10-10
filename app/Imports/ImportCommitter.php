<?php

namespace App\Imports;

use App\Enums\ImportStatus;
use App\Enums\PeriodStatus;
use App\Models\Import;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Turns a previewed import into lines on people's reconciliations, all or nothing. */
final class ImportCommitter
{
    public function __construct(private ImportPreviewer $previewer) {}

    /**
     * @param  list<int>  $skip  row numbers to leave out
     * @param  list<int>  $includeDuplicates  duplicate rows to import anyway
     * @param  array<int, array{date?: ?string, amount?: ?string, description?: ?string}>  $fixes
     *
     * @throws ImportNotReady
     */
    public function commit(Import $import, User $admin, array $skip = [], array $includeDuplicates = [], array $fixes = []): CommitResult
    {
        return DB::transaction(function () use ($import, $admin, $skip, $includeDuplicates, $fixes) {
            // Lock the period first (then the import), the same order as publish/remove/reassign, so
            // overlapping confirms can't both miss each other's lines and a publish can't slip in between.
            $period = Period::query()->whereKey($import->period_id)->lockForUpdate()->firstOrFail();
            $import = Import::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            $import->setRelation('period', $period);

            if ($import->status !== ImportStatus::Draft) {
                throw new ImportNotReady('This file has already been imported.');
            }

            if ($period->status === PeriodStatus::Closed) {
                throw new ImportNotReady('This period is closed.');
            }

            $preview = $this->previewer->preview($import, $fixes);
            $skip = array_flip($skip);
            $includeDuplicates = array_flip($includeDuplicates);

            $unresolved = array_values(array_filter($preview->errorRowNumbers(), fn (int $row) => ! isset($skip[$row])));
            if ($unresolved !== []) {
                throw ImportNotReady::unresolvedRows($unresolved);
            }

            /** @var array<int, Reconciliation> $reconciliations */
            $reconciliations = [];
            $added = [];
            $created = $unassigned = $skippedDuplicates = 0;

            foreach ($preview->rows as $row) {
                $rowNumber = $row->parsed->rowNumber;

                if (isset($skip[$rowNumber])) {
                    continue;
                }

                if ($row->duplicate && ! isset($includeDuplicates[$rowNumber])) {
                    $skippedDuplicates++;

                    continue;
                }

                $reconciliationId = null;
                if ($row->userId !== null) {
                    $reconciliations[$row->userId] ??= Reconciliation::firstOrCreate(['period_id' => $period->id, 'user_id' => $row->userId]);
                    $reconciliationId = $reconciliations[$row->userId]->id;
                    $added[$row->userId] = ($added[$row->userId] ?? 0) + 1;
                } else {
                    $unassigned++;
                }

                $import->transactions()->create([
                    'period_id' => $period->id,
                    'reconciliation_id' => $reconciliationId,
                    'row_number' => $rowNumber,
                    'transacted_on' => $row->parsed->date,
                    'description' => $row->parsed->description,
                    'amount' => $row->parsed->amount,
                    'card_ref' => $row->parsed->cardRef(),
                    'extra' => $row->parsed->extra ?: null,
                ]);
                $created++;
            }

            $import->update(['status' => ImportStatus::Confirmed, 'row_count' => $created]);

            foreach ($reconciliations as $userId => $reconciliation) {
                $reconciliation->linesAdded($added[$userId]);
            }

            $result = new CommitResult($created, $unassigned, $skippedDuplicates);

            activity('periods')
                ->causedBy($admin)
                ->performedOn($period)
                ->event('import_confirmed')
                ->withProperties(['import_id' => $import->id, 'file' => $import->original_filename, 'created' => $created, 'unassigned' => $unassigned, 'duplicates_skipped' => $skippedDuplicates])
                ->log($result->message());

            return $result;
        });
    }
}
