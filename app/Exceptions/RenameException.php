<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown when a rename operation fails or completes partially.
 */
class RenameException extends StorageException {}
