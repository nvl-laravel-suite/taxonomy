<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Enums;

use Nvl\Support\Contracts\ResponseCode;

/** Stable public response discriminators for Taxonomy.
 * @api
 */
enum TaxonomyResponseCode: string implements ResponseCode
{
    case InvalidTermOperation = 'invalid_term_operation';
    case TermNotFound = 'term_not_found';
    case OperationFailed = 'operation_failed';
    case UnsafeTermDeletion = 'unsafe_term_deletion';
    case FlatVocabulary = 'flat_vocabulary';
    case AmbiguousTermReference = 'ambiguous_term_reference';
    case ClosedVocabulary = 'closed_vocabulary';
    case CircularHierarchy = 'circular_hierarchy';
    case StaleTermVersion = 'stale_term_version';
    case UnknownTaxonomy = 'unknown_taxonomy';
    case InvalidParent = 'invalid_parent';
    case BatchReadUnavailable = 'batch_read_unavailable';
    case MaximumDepthExceeded = 'maximum_depth_exceeded';
    case DuplicateSiblingSlug = 'duplicate_sibling_slug';
}
