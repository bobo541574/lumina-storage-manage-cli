<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown when the referenced storage bucket cannot be found.
 */
class BucketNotFoundException extends StorageException {}
