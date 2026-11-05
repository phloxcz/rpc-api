<?php

declare(strict_types=1);

use Nette\Bootstrap\Configurator;
use Nette\DI\Container;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

/**
 * Compiles a real container from the given NEON config.
 */
function createContainer(string $neon, bool $debugMode = false, bool $withApplication = true): Container
{
    @mkdir(__DIR__ . '/tmp');
    $tempDir = __DIR__ . '/tmp/' . getmypid();
    Tester\Helpers::purge($tempDir);

    $configurator = new Configurator();
    $configurator->setDebugMode($debugMode);
    $configurator->setTempDirectory($tempDir);
    $configurator->addStaticParameters(['appDir' => __DIR__]);
    if (!$withApplication) {
        unset($configurator->defaultExtensions['application']);
    }

    // The name of the compiled class depends on the path, not on the content
    $configFile = $tempDir . '/' . md5($neon . (int) $debugMode . (int) $withApplication) . '.neon';
    file_put_contents($configFile, <<<NEON
extensions:
    rpcApi: Phlox\\RpcApi\\DI\\Extension

security:
    debugger: false

http:
    frames: null

$neon
NEON);
    $configurator->addConfig($configFile);

    return $configurator->createContainer();
}

const StandardConfig = <<<'NEON'
rpcApi:
    endpoints:
        api:
            namespace: Phlox\RpcApi\Tests\Fixtures\Api
            maxBatchSize: 5

services:
    - Phlox\RpcApi\Tests\Fixtures\Api\UserController
    - Phlox\RpcApi\Tests\Fixtures\Api\PublicController
    - Phlox\RpcApi\Tests\Fixtures\Api\AdminController
    - Phlox\RpcApi\Tests\Fixtures\Api\RenamedController
    - Phlox\RpcApi\Tests\Fixtures\ApiOther\OtherController
NEON;
