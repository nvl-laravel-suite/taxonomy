<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Relations;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * Internal expression comparing two grammar-wrapped package-owned columns.
 */
final readonly class TextColumnComparison implements Expression
{
    /**
     * Create one grammar-wrapped textual column comparison.
     */
    public function __construct(
        private string $left,
        private string $right,
    ) {}

    /**
     * Return the grammar-ready comparison SQL.
     */
    public function getValue(Grammar $grammar): string
    {
        return "{$this->left} = {$this->right}";
    }
}
