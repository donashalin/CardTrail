<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case Todo = 'todo';
    case Done = 'done';
    case Queried = 'queried';
    case Approved = 'approved';
}
