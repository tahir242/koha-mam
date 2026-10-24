<?php

namespace App\Assets;

use RuntimeException;

/** Validation failure with a message safe to show to staff. */
class UploadRejectedException extends RuntimeException {}
