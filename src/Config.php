<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate;

use Keboola\Component\Config\BaseConfig;

class Config extends BaseConfig
{
    public function getSourceProjectUrl(): string
    {
        return $this->getValue(['parameters', 'sourceKbcUrl']);
    }

    public function getSourceProjectToken(): string
    {
        return $this->getValue(['parameters', '#sourceKbcToken']);
    }
}
