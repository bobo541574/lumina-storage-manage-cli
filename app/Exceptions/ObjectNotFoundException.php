<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown when the referenced object/file cannot be found.
 */
class ObjectNotFoundException extends StorageException {}
