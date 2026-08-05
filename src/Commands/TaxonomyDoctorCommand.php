<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Commands;

use Illuminate\Console\Command;
use Nvl\Taxonomy\Services\TaxonomyDoctor;

/**
 * Reports Taxonomy package readiness without mutation.
 */
final class TaxonomyDoctorCommand extends Command
{
    protected $signature = 'nvl:taxonomy:doctor
        {--strict : Treat warnings as failures}
        {--format=text : Output format: text or json}';

    protected $description = 'Inspect taxonomy schema and registries';

    /**
     * Render taxonomy diagnostics in the requested supported format.
     */
    public function handle(TaxonomyDoctor $doctor): int
    {
        $format = $this->option('format');

        if (! is_string($format) || ! in_array($format, ['text', 'json'], true)) {
            $this->error('The taxonomy doctor format must be [text] or [json].');

            return self::FAILURE;
        }

        $checks = $doctor->inspect();
        $strict = (bool) $this->option('strict');
        $failed = collect($checks)->contains(
            static fn ($check): bool => ! $check->passed
                && ($strict || $check->severity === 'error'),
        );

        if ($format === 'json') {
            $this->line((string) json_encode([
                'healthy' => ! $failed,
                'checks' => array_map(
                    static fn ($check): array => [
                        'key' => $check->key,
                        'severity' => $check->severity,
                        'passed' => $check->passed,
                        'message' => $check->message,
                    ],
                    $checks,
                ),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            foreach ($checks as $check) {
                $this->line(sprintf(
                    '[%s] %s: %s',
                    $check->passed ? 'PASS' : strtoupper($check->severity),
                    $check->key,
                    $check->message,
                ));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
