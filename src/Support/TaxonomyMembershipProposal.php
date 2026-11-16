<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Support;

use InvalidArgumentException;
use Nvl\Taxonomy\Models\Term;

/** Proposed complete membership and its source identity.
 * @api
 */
final readonly class TaxonomyMembershipProposal
{
    /** @var list<Term|string> */
    public array $terms;

    /** @param array<array-key,mixed> $terms Ordered, pre-validation rule output. */
    public function __construct(public string $source, array $terms)
    {
        if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $source) !== 1 || ! array_is_list($terms)) {
            throw new InvalidArgumentException('Taxonomy membership proposals require a canonical source and an ordered term list.');
        }
        if (count($terms) > TaxonomyConfiguration::positiveLimit('bulk_terms', 500)) {
            throw new InvalidArgumentException('Taxonomy membership proposal exceeds the configured bulk term limit.');
        }
        $validated = [];
        foreach ($terms as $term) {
            if (! is_string($term) && ! $term instanceof Term) {
                throw new InvalidArgumentException('Each membership proposal entry must be a term or a term reference.');
            }
            $validated[] = $term;
        }
        $this->terms = $validated;
    }
}
