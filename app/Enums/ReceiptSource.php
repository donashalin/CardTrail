<?php

namespace App\Enums;

enum ReceiptSource: string
{
    case Camera = 'camera';
    case Upload = 'upload';
    case Bulk = 'bulk';
    case Email = 'email';
}
