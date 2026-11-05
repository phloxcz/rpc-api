<?php

/**
 * Only unexpected exceptions get logged, and a broken logger doesn't break the response.
 */

declare(strict_types=1);

use Phlox\ApiCore\ApiException;
use Phlox\RpcApi\Dispatcher;
use Phlox\RpcApi\Tests\Fixtures\RecordingLogger;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$container = createContainer(StandardConfig . <<<'NEON'

    tracy.logger: Phlox\RpcApi\Tests\Fixtures\RecordingLogger
NEON);

$dispatcher = $container->getByType(Dispatcher::class);
$logger = $container->getByType(RecordingLogger::class);

$call = static fn(string $method): array => $dispatcher->handle(
    'api',
    json_encode(['jsonrpc' => '2.0', 'method' => $method, 'id' => 1]),
);

$call('public.fail');
$call('does.notExist');
Assert::same([], $logger->logged, 'errors meant for the client are not logged');

$call('public.crash');
Assert::count(1, $logger->logged);
Assert::type(RuntimeException::class, $logger->logged[0]);
Assert::same('secret crash detail', $logger->logged[0]->getMessage());

// error_log() is the fallback; keep it out of the test's output
ini_set('error_log', __DIR__ . '/tmp/' . getmypid() . '/error.log');

$logger->broken = true;
$response = $call('public.crash');
Assert::same(ApiException::E_INTERNAL_ERROR, $response['error']['code']);
Assert::same(1, $response['id']);
