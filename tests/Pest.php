<?php

declare(strict_types=1);

use Nvl\Taxonomy\Tests\StandaloneTestCase;
use Nvl\Taxonomy\Tests\TestCase;

uses(TestCase::class)->in('TaxonomyTest.php', 'TaxonomyConsumerContractsTest.php', 'BatchedOwnerReadsTest.php', 'NativeTaxonomyServicesTest.php');
uses(StandaloneTestCase::class)->in('StandaloneBatchedOwnerReadsTest.php');
