<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Taxonomy\Contracts\TaxonomyMembershipProvider;
use Nvl\Taxonomy\Enums\DeleteTermStrategy;
use Nvl\Taxonomy\Enums\TaxonomyMembershipMode;
use Nvl\Taxonomy\Providers\TaxonomyServiceProvider;
use Nvl\Taxonomy\Services\TaxonomyAssignments;
use Nvl\Taxonomy\Services\TaxonomyTerms;
use Nvl\Taxonomy\Support\TaxonomyDefinition;
use Nvl\Taxonomy\Support\TaxonomyMembershipProposal;
use Nvl\Taxonomy\Tests\Fixtures\MembershipRaceWorkerProvider;
use Nvl\Taxonomy\Tests\Fixtures\Post;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\Foundation\Application;

require dirname(__DIR__, 5).'/vendor/autoload.php';

$source = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$options = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
if (! is_array($source) || ($source['driver'] ?? null) !== 'pgsql'
    || ! is_array($options) || ! is_string($options['directory'] ?? null) || ! is_dir($options['directory'])
    || preg_match('/^nvl-taxonomy-membership-race-[a-f0-9]{16}$/D', basename($options['directory'])) !== 1
    || ! in_array($options['role'] ?? null, ['reconcile', 'sync', 'race-sync', 'race-delete'], true) || ! is_int($options['owner'] ?? null)) {
    throw new RuntimeException('Membership race workers require the committed PostgreSQL source and barrier directory.');
}

/** Wait for one barrier file written by the parent test. */
$await = static function (string $path): void {
    $deadline = microtime(true) + 15;
    while (! is_file($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException("The membership race barrier [{$path}] timed out.");
        }
        usleep(10000);
        clearstatcache();
    }
};
/** Publish one JSON barrier file atomically. */
$signal = static function (string $path, array $payload): void {
    file_put_contents($path.'.pending', json_encode($payload, JSON_THROW_ON_ERROR));
    rename($path.'.pending', $path);
};

$configuration = [];
foreach ($source as $key => $value) {
    if (is_string($key)) {
        $configuration[$key] = $value;
    }
}
MembershipRaceWorkerProvider::$source = $configuration;
$app = Application::create(options: ['load_environment_variables' => false, 'extra' => [
    'dont-discover' => ['*'],
    'providers' => [
        MembershipRaceWorkerProvider::class, LocaleServiceProvider::class, SupportServiceProvider::class,
        TranslatableServiceProvider::class, TaxonomyServiceProvider::class,
    ],
]]);
try {
    $app->make(Kernel::class)->bootstrap();
    $connection = $app->make('db')->connection();
    $physical = $connection->selectOne('select current_database() as database, pg_backend_pid() as pid');
    $pid = is_object($physical) ? filter_var($physical->pid ?? null, FILTER_VALIDATE_INT) : false;
    if (! is_object($physical) || ! is_string($physical->database ?? null) || ! is_int($pid)) {
        throw new RuntimeException('Membership race workers require a physical PostgreSQL session identity.');
    }
    $identity = ['database' => $physical->database, 'pid' => $pid];
    $directory = $options['directory'];
    $post = Post::query()->findOrFail($options['owner']);
    $assignments = $app->make(TaxonomyAssignments::class);
    /**
     * Start a race role. The holder starts on go and parks inside its policy review with its locks held;
     * the waiter starts on go-waiter, after the parent has seen the holder parked.
     */
    $raceStart = static function (mixed $holds) use ($signal, $await, $directory, $identity, $options): void {
        if (! is_bool($holds)) {
            throw new RuntimeException('Race workers must be told whether they hold the lock boundary.');
        }
        if ($holds) {
            MembershipRaceWorkerProvider::$onFirstReview = static function () use ($signal, $await, $directory, $identity): void {
                $signal($directory.'/holding', $identity);
                $await($directory.'/release');
            };
        }
        $signal($directory.'/ready-'.$options['role'], $identity);
        $await($directory.($holds ? '/go' : '/go-waiter'));
    };

    if ($options['role'] === 'reconcile') {
        $provider = new class($signal, $await, $directory, $identity) implements TaxonomyMembershipProvider
        {
            /** @param array<string, mixed> $identity */
            public function __construct(private Closure $signal, private Closure $await, private string $directory, private array $identity) {}

            /** Hold the owner lock until the parent has seen the manual sync blocked behind it. */
            public function propose(Model $owner, TaxonomyDefinition $taxonomy, Authenticatable|SystemActor $actor): TaxonomyMembershipProposal
            {
                ($this->signal)($this->directory.'/holding', $this->identity);
                ($this->await)($this->directory.'/release');

                return new TaxonomyMembershipProposal('shopify', ['ruled-a', 'ruled-b']);
            }

            public function record(Model $owner, TaxonomyDefinition $taxonomy, Authenticatable|SystemActor $actor, TaxonomyMembershipProposal $proposal, TaxonomyMembershipMode $mode): void {}
        };
        $mode = $assignments->reconcile($post, 'tag', $provider, SystemActor::Background);
        $outcome = ['status' => 'reconciled', 'mode' => $mode->value];
    } elseif ($options['role'] === 'sync') {
        $signal($directory.'/ready-sync', $identity);
        $await($directory.'/go');
        $assignments->sync($post, 'tag', ['manual'], SystemActor::Background);
        $outcome = ['status' => 'synced'];
    } elseif ($options['role'] === 'race-sync') {
        $raceStart($options['holds'] ?? null);
        $assignments->sync($post, 'tag', ['race-term'], SystemActor::Background);
        $outcome = ['status' => 'synced'];
    } else {
        if (! is_string($options['term'] ?? null) || ! is_int($options['revision'] ?? null)) {
            throw new RuntimeException('The delete race worker requires the term identity and revision.');
        }
        $raceStart($options['holds'] ?? null);
        $deleted = $app->make(TaxonomyTerms::class)->delete($options['term'], $options['revision'], SystemActor::Background, DeleteTermStrategy::Detach);
        $outcome = ['status' => $deleted ? 'deleted' : 'kept'];
    }

    fwrite(STDOUT, json_encode([...$outcome, ...$identity], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(1);
}
