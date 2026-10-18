<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Config\PackageStorage;

/** Native host owner without an NVL tenancy or authentication package. */
final class OwnershipCompany extends Model
{
    protected $table = 'taxonomy_owned_companies';

    protected $guarded = [];

    public $timestamps = false;

    public function getConnectionName(): ?string
    {
        return PackageStorage::connection('taxonomy');
    }
}
