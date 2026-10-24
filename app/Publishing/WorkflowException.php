<?php

namespace App\Publishing;

use RuntimeException;

/** A workflow action that is not allowed in the asset's current situation. */
class WorkflowException extends RuntimeException {}
