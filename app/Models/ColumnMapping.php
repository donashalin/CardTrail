<?php

namespace App\Models;

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
}
