<?php

namespace App\Models;

use App\Imports\Parsing\HeaderSignature;
use App\Imports\RowParser;
use Illuminate\Database\Eloquent\Model;

/** A remembered column layout, found again by the signature of a file's header row. */
class ColumnMapping extends Model
{
    protected $fillable = ['name', 'header_signature', 'header_row', 'mapping'];

    protected function casts(): array
    {
        return [
            'header_row' => 'integer',
            'mapping' => 'array',
        ];
    }

    /**
     * Look for a saved layout among a file's first rows.
     *
     * @param  array<int, list<mixed>>  $rows  rows keyed by row number
     * @return array{0: self, 1: int}|null  the saved mapping and the row its headers are on
     */
    public static function matchFirstRows(array $rows, int $limit = 10): ?array
    {
        foreach (array_slice($rows, 0, $limit, true) as $rowNumber => $cells) {
            $saved = self::query()
                ->where('header_signature', HeaderSignature::for(array_map(RowParser::text(...), $cells)))
                ->first();

            if ($saved) {
                return [$saved, $rowNumber];
            }
        }

        return null;
    }
}
