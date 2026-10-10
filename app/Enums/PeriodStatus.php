<?php

namespace App\Enums;

enum PeriodStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Closed = 'closed';
}
