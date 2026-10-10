<?php

namespace App\Imports;

final readonly class ImportPreview
{
    /** @param  list<PreviewRow>  $rows */
    public function __construct(public array $rows) {}

    /** @return list<int> */
    public function errorRowNumbers(): array
    {
        return array_values(array_map(
            fn (PreviewRow $row) => $row->parsed->rowNumber,
            array_filter($this->rows, fn (PreviewRow $row) => ! $row->parsed->isValid()),
        ));
    }

    /** @param  array<int, string>  $userNames */
    public function summary(array $userNames): array
    {
        $importable = array_filter($this->rows, fn (PreviewRow $row) => $row->parsed->isValid() && ! $row->duplicate);

        $byUser = [];
        foreach ($importable as $row) {
            if ($row->userId !== null) {
                $byUser[$row->userId] = ($byUser[$row->userId] ?? 0) + 1;
            }
        }

        $byUser = collect($byUser)
            ->map(fn (int $count, int $userId) => ['user_id' => $userId, 'name' => $userNames[$userId] ?? 'Unknown', 'count' => $count])
            ->sortBy(fn (array $entry) => mb_strtolower($entry['name']))
            ->values()
            ->all();

        return [
            'total' => count($this->rows),
            'errors' => count($this->errorRowNumbers()),
            'duplicates' => count(array_filter($this->rows, fn (PreviewRow $row) => $row->duplicate)),
            'unassigned' => count(array_filter($importable, fn (PreviewRow $row) => $row->userId === null)),
            'by_user' => $byUser,
        ];
    }
}
