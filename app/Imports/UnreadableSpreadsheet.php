<?php

namespace App\Imports;

use RuntimeException;

/** The uploaded file can't be used. The message is safe to show the admin. */
class UnreadableSpreadsheet extends RuntimeException {}
