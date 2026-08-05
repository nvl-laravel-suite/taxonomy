<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Exceptions;

/**
 * Raised when a hierarchy mutation would create or preserve a cycle.
 */
class CircularHierarchyException extends TaxonomyException {}
