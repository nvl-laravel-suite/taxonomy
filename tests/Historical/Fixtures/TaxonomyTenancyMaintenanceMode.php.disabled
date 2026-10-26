<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Illuminate\Contracts\Foundation\MaintenanceMode;

/** Keeps fixture adoption maintenance active until installation completes. */
final class TaxonomyTenancyMaintenanceMode implements MaintenanceMode
{
    private bool $enabled = true;

    /** @var array<string, mixed> */
    private array $payload = [];

    /** @param array<string, mixed> $payload */
    public function activate(array $payload): void
    {
        $this->enabled = true;
        $this->payload = $payload;
    }

    /** End fixture maintenance. */
    public function deactivate(): void
    {
        $this->enabled = false;
    }

    /** Report whether maintenance remains active. */
    public function active(): bool
    {
        return $this->enabled;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->payload;
    }
}
