<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

/** Ordinary Laravel actor admitted by the fixture host policy. */
final class OwnershipActor extends User
{
    protected $table = 'taxonomy_owned_actors';

    protected $guarded = [];

    public $timestamps = false;
}
