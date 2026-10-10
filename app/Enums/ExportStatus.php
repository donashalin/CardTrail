<?php

namespace App\Enums;

enum ExportStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
