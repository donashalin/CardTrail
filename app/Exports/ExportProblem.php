<?php

namespace App\Exports;

use RuntimeException;

/** A reason an export can't be built that the admin can act on. Its message is shown to them as it is. */
final class ExportProblem extends RuntimeException {}
