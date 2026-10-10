<?php

namespace App\Imports;

use Illuminate\Support\Str;

final readonly class CommitResult
{
    public function __construct(
        public int $created,
        public int $unassigned,
        public int $skippedDuplicates,
    ) {}

    public function message(): string
    {
        $notes = array_filter([
            $this->unassigned > 0 ? "{$this->unassigned} unassigned" : null,
            $this->skippedDuplicates > 0 ? $this->skippedDuplicates.' '.Str::plural('duplicate', $this->skippedDuplicates).' skipped' : null,
        ]);

        return "Imported {$this->created} ".Str::plural('line', $this->created)
            .($notes ? ' ('.implode(', ', $notes).')' : '')
            .'.';
    }
}
