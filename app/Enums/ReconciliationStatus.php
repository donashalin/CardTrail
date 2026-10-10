<?php

namespace App\Enums;

enum ReconciliationStatus: string
{
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Approved = 'approved';
}
