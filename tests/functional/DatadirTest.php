<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\DatadirTests;

use Keboola\DatadirTests\DatadirTestCase;

class DatadirTest extends DatadirTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $nonMasterDestToken = getenv('DEST_STORAGE_API_TOKEN_NOT_MASTER');
        putenv('DEST_STORAGE_API_TOKEN=' . $nonMasterDestToken);
    }
}
