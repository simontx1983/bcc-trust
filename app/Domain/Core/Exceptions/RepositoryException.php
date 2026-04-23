<?php
/**
 * Repository Exception
 *
 * Thrown by repository write methods when a database operation fails.
 * Controllers and services should catch this and return a 500 response.
 *
 * Separate from generic \RuntimeException so callers can distinguish
 * database write failures from other runtime errors.
 *
 * @package BCC\Trust\Core\Exceptions
 * @version 1.0.0
 */

namespace BCC\Trust\Core\Exceptions;

if (!defined('ABSPATH')) {
    exit;
}

class RepositoryException extends \RuntimeException {}
