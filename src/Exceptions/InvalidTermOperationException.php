<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Exceptions;

use InvalidArgumentException;
use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Exceptions\ExceptionResponse;
use Nvl\Support\Traits\InteractsWithPackageFailure;
use Nvl\Taxonomy\Enums\TaxonomyResponseCode;

/** Expected taxonomy membership, merge or attachment constraint failure.
 * @api
 */
final class InvalidTermOperationException extends InvalidArgumentException implements RespondableException
{
    use InteractsWithPackageFailure;

    /** Resolve safe metadata for invalid consumer operations. */
    protected function exceptionResponse(): ExceptionResponse
    {
        return new ExceptionResponse('taxonomy', TaxonomyResponseCode::InvalidTermOperation, 422);
    }
}
