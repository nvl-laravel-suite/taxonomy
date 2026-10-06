<?php

declare(strict_types=1);

use Nvl\Taxonomy\Tests\StandaloneTestCase;
use Nvl\Taxonomy\Tests\TaxonomyTenancyTestCase;
use Nvl\Taxonomy\Tests\TestCase;

uses(TestCase::class)->in('TaxonomyTest.php', 'TaxonomyConsumerContractsTest.php', 'BatchedOwnerReadsTest.php');
uses(TaxonomyTenancyTestCase::class)->in('Tenancy');
uses(StandaloneTestCase::class)->in('StandaloneBatchedOwnerReadsTest.php');
