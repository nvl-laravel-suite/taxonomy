<?php

declare(strict_types=1);

use Nvl\Taxonomy\Models\Term;

return [
    'models' => ['term' => Term::class],
    'ownership' => ['owner_resources' => []], 'owners' => [], 'migrations' => ['enabled' => true], 'locks' => ['seconds' => 30], 'connection' => null];
