<?php

namespace App\Reviews;

use RuntimeException;

/** A review action that isn't allowed right now. The message is safe to show the accountant. */
final class CannotReview extends RuntimeException {}
