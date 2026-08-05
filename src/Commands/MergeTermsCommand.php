<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Nvl\Taxonomy\Actions\MergeTermsAction;
use Nvl\Taxonomy\Actions\ValidateTermMergeAction;
use Nvl\Taxonomy\Exceptions\AmbiguousTermReferenceException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Support\TaxonomyRegistry;

/**
 * Executes a locked, rerunnable term merge with an inspect-only mode.
 */
final class MergeTermsCommand extends Command
{
    protected $signature = 'nvl:taxonomy:merge
        {taxonomy : Registered vocabulary key}
        {source : Source UUID or slug}
        {destination : Destination UUID or slug}
        {--dry-run : Validate and report without mutation}
        {--force : Skip confirmation}';

    protected $description = 'Merge a source taxonomy term into a destination';

    /**
     * Execute or preview one revision-checked term merge.
     */
    public function handle(
        MergeTermsAction $merge,
        ValidateTermMergeAction $validate,
        TaxonomyRegistry $taxonomies,
    ): int {
        $taxonomy = (string) $this->argument('taxonomy');
        $modelClass = $taxonomies->get($taxonomy)->model;
        $source = $this->resolve($modelClass, $taxonomy, (string) $this->argument('source'));
        $destination = $this->resolve(
            $modelClass,
            $taxonomy,
            (string) $this->argument('destination'),
        );

        if (! $source instanceof Term || ! $destination instanceof Term) {
            $this->error('Could not find both source and destination terms.');

            return self::FAILURE;
        }

        if ($source->is($destination)) {
            $this->error('Source and destination must differ.');

            return self::FAILURE;
        }

        $message = sprintf(
            'Merge [%s] into [%s].',
            $source->displayName() ?: $source->slug,
            $destination->displayName() ?: $destination->slug,
        );

        if ((bool) $this->option('dry-run')) {
            $validate->execute(
                $source,
                $destination,
                $source->revision,
                $destination->revision,
            );
            $this->info('[Dry run] '.$message);

            return self::SUCCESS;
        }

        if (! (bool) $this->option('force') && ! $this->confirm($message)) {
            return self::FAILURE;
        }

        $merge->execute(
            $source,
            $destination,
            $source->revision,
            $destination->revision,
        );
        $this->info($message);

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Term>  $modelClass
     */
    private function resolve(string $modelClass, string $taxonomy, string $identifier): ?Term
    {
        if (Str::isUuid($identifier)) {
            return $modelClass::query()
                ->where('taxonomy', $taxonomy)
                ->find($identifier);
        }

        $terms = $modelClass::query()
            ->where('taxonomy', $taxonomy)
            ->where('slug', $identifier)
            ->limit(2)
            ->get();

        if ($terms->count() > 1) {
            throw AmbiguousTermReferenceException::forSlug($taxonomy, $identifier);
        }

        return $terms->first();
    }
}
