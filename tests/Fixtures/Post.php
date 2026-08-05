<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Taxonomy\Concerns\HasTaxonomies;

class Post extends Model
{
    use HasTaxonomies;

    protected $guarded = [];

    protected $table = 'taxonomy_posts';

    /** @var list<string> */
    protected array $taxonomies = ['tag', 'category'];

    /**
     * Create the test-only taxonomy owner table.
     */
    public static function migrate(): void
    {
        Schema::create('taxonomy_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
    }
}
