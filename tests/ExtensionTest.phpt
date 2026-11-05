<?php

/**
 * Failures that must surface when the container compiles.
 */

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// Wildcard with credentials is refused
Assert::exception(static fn() => createContainer(<<<'NEON'
rpcApi:
    endpoints:
        api:
            namespace: App\Api
            accessControl:
                allowedOrigins: ['*']
                allowCredentials: true
NEON), InvalidArgumentException::class, "%A%can't be combined with the '*' origin%A%");

// Two controllers can't claim one JSON-RPC method
Assert::exception(static fn() => createContainer(<<<'NEON'
rpcApi:
    endpoints:
        api:
            namespace: Phlox\RpcApi\Tests\Fixtures\DuplicateNames

services:
    - Phlox\RpcApi\Tests\Fixtures\DuplicateNames\AController
    - Phlox\RpcApi\Tests\Fixtures\DuplicateNames\BController
NEON), Nette\InvalidStateException::class, "%A%'same.read' is defined by both%A%");

// A namespace is required
Assert::exception(static fn() => createContainer(<<<'NEON'
rpcApi:
    endpoints:
        api:
            maxBatchSize: 5
NEON), Nette\DI\InvalidConfigurationException::class, '%A%namespace%A%');
