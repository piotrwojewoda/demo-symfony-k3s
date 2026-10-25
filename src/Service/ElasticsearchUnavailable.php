<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Raised when Elasticsearch cannot be reached or answers with an error.
 * Controllers turn it into a friendly "search unavailable" response, so the
 * demo never leaks a stack trace (spec 008, FR-005).
 */
final class ElasticsearchUnavailable extends \RuntimeException
{
}
