<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Exceptions;

use Exception;
use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Exceptions\ExceptionResponse;
use Nvl\Support\Traits\InteractsWithPackageFailure;
use Nvl\Taxonomy\Enums\TaxonomyResponseCode;

/**
 * @api
 * Base exception for taxonomy domain contract violations.
 */
class TaxonomyException extends Exception implements RespondableException
{
    use InteractsWithPackageFailure;

    /** Resolve the declared safe failure for this native hierarchy. */
    protected function exceptionResponse(): ExceptionResponse
    {
        return match (static::class) {
            UnsafeTermDeletionException::class => new ExceptionResponse('taxonomy', TaxonomyResponseCode::UnsafeTermDeletion, 409),
            FlatVocabularyException::class => new ExceptionResponse('taxonomy', TaxonomyResponseCode::FlatVocabulary, 422),
            AmbiguousTermReferenceException::class => new ExceptionResponse('taxonomy', TaxonomyResponseCode::AmbiguousTermReference, 422),
            ClosedVocabularyException::class => new ExceptionResponse('taxonomy', TaxonomyResponseCode::ClosedVocabulary, 422),
            CircularHierarchyException::class => new ExceptionResponse('taxonomy', TaxonomyResponseCode::CircularHierarchy, 409),
            StaleTermVersionException::class => new ExceptionResponse('taxonomy', TaxonomyResponseCode::StaleTermVersion, 409),
            UnknownTaxonomyException::class => new ExceptionResponse('taxonomy', TaxonomyResponseCode::UnknownTaxonomy, 404),
            InvalidParentException::class => new ExceptionResponse('taxonomy', TaxonomyResponseCode::InvalidParent, 422),
            TaxonomyBatchReadException::class => new ExceptionResponse('taxonomy', TaxonomyResponseCode::BatchReadUnavailable, 500),
            MaximumDepthExceededException::class => new ExceptionResponse('taxonomy', TaxonomyResponseCode::MaximumDepthExceeded, 422),
            DuplicateSiblingSlugException::class => new ExceptionResponse('taxonomy', TaxonomyResponseCode::DuplicateSiblingSlug, 409),
            default => new ExceptionResponse('taxonomy', TaxonomyResponseCode::OperationFailed),
        };
    }
}
