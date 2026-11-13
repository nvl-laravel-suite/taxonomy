<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Nvl\Taxonomy\Concerns\HasTaxonomies;

/**
 * Test-only taxonomy owner with a non-conventional string primary key.
 *
 * @property string $post_key
 * @property string $title
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CustomKeyPost extends Model
{
    use HasTaxonomies;

    public $incrementing = false;

    protected $guarded = [];

    protected $keyType = 'string';

    protected $primaryKey = 'post_key';

    protected $table = 'taxonomy_custom_key_posts';

    /** @var list<string> */
    protected array $taxonomies = ['tag'];

    /**
     * Create the test-only custom-key taxonomy owner table.
     */
    public static function migrate(): void
    {
        Schema::create('taxonomy_custom_key_posts', function (Blueprint $table): void {
            $table->string('post_key')->primary();
            $table->string('title');
            $table->timestamps();
        });
    }
}
