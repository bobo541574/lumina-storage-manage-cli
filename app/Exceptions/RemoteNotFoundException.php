<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown when the configured storage remote cannot be found.
 */
class RemoteNotFoundException extends StorageException {}
