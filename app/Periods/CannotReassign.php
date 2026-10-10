<?php

namespace App\Periods;

use RuntimeException;

/** The message is safe to show the admin. */
class CannotReassign extends RuntimeException {}
