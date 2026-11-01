<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Nvl\Taxonomy\Models\Term;

/** A permitted per-vocabulary model naming the actual default source explicitly. */
class TaxonomyDoctorExplicitDefaultTerm extends Term
{
    protected $connection = 'taxonomy_doctor_default';
}
