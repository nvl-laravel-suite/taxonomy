<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Nvl\Support\Enums\SystemActor;
use Nvl\Taxonomy\Providers\TaxonomyServiceProvider;
use Nvl\Taxonomy\Services\TaxonomyAssignments;
use Nvl\Taxonomy\Services\TaxonomyReader;
use Nvl\Taxonomy\Services\TaxonomyTerms;
use Nvl\Taxonomy\Tests\TestCase;

uses(TestCase::class);

/** @return array<class-string, list<string>> */
function nvlNativeTaxonomyServices(): array
{
    return [
        TaxonomyTerms::class => ['create', 'update', 'delete', 'move', 'merge', 'rebuild'],
        TaxonomyAssignments::class => ['attach', 'detach', 'sync'],
        TaxonomyReader::class => ['resolve', 'forOwners', 'tree', 'term'],
    ];
}

test('published native workflows have explicit actor inputs and typed results', function (): void {
    foreach (nvlNativeTaxonomyServices() as $service => $operations) {
        $class = new ReflectionClass($service);
        expect($class->isFinal())->toBeTrue();
        foreach ($operations as $operation) {
            $method = $class->getMethod($operation);
            $actors = array_values(array_filter($method->getParameters(), static fn (ReflectionParameter $parameter): bool => $parameter->getName() === 'actor'));
            expect($method->isPublic())->toBeTrue()
                ->and($method->isStatic())->toBeFalse()
                ->and($method->hasReturnType())->toBeTrue()
                ->and($actors)->toHaveCount(1)
                ->and((string) $actors[0]->getType())->toBe(Authenticatable::class.'|'.SystemActor::class);
        }
    }
    expect((string) (new ReflectionMethod(TaxonomyReader::class, 'forOwners'))->getReturnType())->toBe('array');
});

test('native provider resolves each workflow and permits late host substitutes', function (): void {
    foreach (array_keys(nvlNativeTaxonomyServices()) as $service) {
        $original = $this->app->make($service);
        expect($original)->toBeInstanceOf($service);
        $host = clone $original;
        $this->app->instance($service, $host);
        expect($this->app->make($service))->toBe($host);
    }
});

test('provider registration preserves early native service instances in another application', function (): void {
    $consumer = new Application($this->app->basePath());
    $consumer->instance('config', new Repository($this->app->make('config')->all()));
    $consumer->instance('env', 'testing');
    $consumer->register(FilesystemServiceProvider::class);
    $hosts = [];
    foreach (array_keys(nvlNativeTaxonomyServices()) as $service) {
        $hosts[$service] = $this->app->make($service);
        $consumer->instance($service, $hosts[$service]);
    }
    try {
        $consumer->register(TaxonomyServiceProvider::class);
        foreach ($hosts as $service => $host) {
            expect($consumer->make($service))->toBe($host);
        }
    } finally {
        Container::setInstance($this->app);
        $consumer->flush();
    }
});
