<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Exceptions;

/**
 * Raised when an operation references an unregistered vocabulary alias.
 */
class UnknownTaxonomyException extends TaxonomyException {}
