<?php

namespace App\Enums;

enum ImportStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
}
