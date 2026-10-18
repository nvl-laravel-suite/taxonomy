<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Taxonomy\Concerns\HasTaxonomies;

/** Native host target independent from NVL tenancy.
 * @property string $id
 * @property int $company_id
 * @property string $name
 * @property string $owner_scope_key
 */
class OwnershipTaxonomyOwner extends Model implements Ownable
{
    use HasOwnership, HasUuids;
    use HasTaxonomies;

    protected $table = 'taxonomy_owned_subjects';

    protected $guarded = [];

    public $timestamps = false;

    public function getConnectionName(): ?string
    {
        return PackageStorage::connection('taxonomy');
    }

    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::column(OwnershipCompany::class, 'company_id');
    }
}
