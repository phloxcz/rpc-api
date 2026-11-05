<?php

/**
 * Internal messages are public only in debug mode.
 */

declare(strict_types=1);

use Phlox\RpcApi\Dispatcher;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$dispatcher = createContainer(StandardConfig, debugMode: true)->getByType(Dispatcher::class);

$call = static fn(string $method): array => $dispatcher->handle(
    'api',
    json_encode(['jsonrpc' => '2.0', 'method' => $method, 'id' => 1]),
);

Assert::contains('secret detail', $call('public.fail')['error']['message']);
Assert::contains('secret crash detail', $call('public.crash')['error']['message']);
