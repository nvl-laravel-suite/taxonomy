<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Nvl\Taxonomy\Models\Term;

/** A vocabulary source that remains incompatible with the package connection. */
class TaxonomyDoctorForeignTerm extends Term
{
    protected $connection = 'taxonomy_doctor_foreign';
}
