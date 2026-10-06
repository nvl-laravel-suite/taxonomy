<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Exceptions;

/** Reports unsupported SQL adapters and explicit bounded read overflow. */
final class TaxonomyBatchReadException extends TaxonomyException {}
