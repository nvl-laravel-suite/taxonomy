<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Enums;

/**
 * Identifies the committed taxonomy mutation represented by a term event.
 */
enum TermChangeOperation: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Moved = 'moved';
    case Reordered = 'reordered';
    case Deleted = 'deleted';
    case Merged = 'merged';
}
