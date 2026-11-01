<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Nvl\Taxonomy\Models\Term;

/** Invalid vocabulary storage must be diagnosed without crashing the inspector. */
class TaxonomyDoctorUnconfiguredTerm extends Term
{
    protected $connection = 'taxonomy_doctor_unconfigured';
}
