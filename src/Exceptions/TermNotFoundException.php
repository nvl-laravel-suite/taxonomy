<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Exceptions;

use InvalidArgumentException;
use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Exceptions\ExceptionResponse;
use Nvl\Support\Traits\InteractsWithPackageFailure;
use Nvl\Taxonomy\Enums\TaxonomyResponseCode;

/** Expected failure to resolve the requested canonical merge terms.
 * @api
 */
final class TermNotFoundException extends InvalidArgumentException implements RespondableException
{
    use InteractsWithPackageFailure;

    protected function exceptionResponse(): ExceptionResponse
    {
        return new ExceptionResponse('taxonomy', TaxonomyResponseCode::TermNotFound, 404);
    }
}
