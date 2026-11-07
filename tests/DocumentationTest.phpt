<?php

/**
 * The OpenRPC document, DTOs going through a real dispatcher, and when the server
 * takes over a request.
 */

declare(strict_types=1);

use Phlox\ApiCore\ProtocolError;
use Phlox\ApiCore\Attributes\ErrorCase;
use Nette\InvalidStateException;
use Phlox\ApiCore\ApiException;
use Phlox\RpcApi\Dispatcher;
use Phlox\RpcApi\OpenRpc;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/Docs/DocsController.php';

$config = static fn(string $documentation = '{}', string $namespace = 'Docs', string $class = 'DocsController'): string => <<<NEON
rpcApi:
    endpoints:
        api:
            namespace: Phlox\\RpcApi\\Tests\\Fixtures\\$namespace
            documentation: $documentation

services:
    - Phlox\\RpcApi\\Tests\\Fixtures\\$namespace\\$class
NEON;

$source = static fn(Nette\DI\Container $container): string => (string) file_get_contents((new ReflectionClass($container))->getFileName());

$container = createContainer($config('{expose: true, info: {title: Users, version: "2.0.0"}, extra: {x-custom: 1}}'));
$containerSource = $source($container); // the next createContainer() removes the file
$dispatcher = $container->getByType(Dispatcher::class);
$openRpc = $container->getByType(OpenRpc::class);

$document = json_decode($openRpc->toJson('api'), true);

Assert::same('1.3.2', $document['openrpc']);
Assert::same(['title' => 'Users', 'version' => '2.0.0'], $document['info']);
Assert::false(isset($document['servers']), 'OpenRPC wants an absolute URL, which only the configuration knows');
Assert::same(1, $document['x-custom']);

$methods = array_column($document['methods'], null, 'name');
Assert::same(['docs.nothing', 'docs.save', 'docs.secured', 'docs.unread'], array_keys($methods));

$save = $methods['docs.save'];
Assert::same('Saves a user.', $save['summary']);
Assert::same('With details.', $save['description']);
Assert::same([['name' => 'Docs']], $save['tags']);
Assert::same('either', $save['paramStructure'], 'parameters can be named or positional');
Assert::same(
    [
        ['name' => 'input', 'required' => true, 'schema' => ['$ref' => '#/components/schemas/UserDto'], 'description' => 'Who'],
        ['name' => 'limit', 'required' => false, 'schema' => ['default' => 5, 'minimum' => 1, 'type' => 'integer'], 'description' => 'How many'],
    ],
    $save['params'],
);
Assert::same(['name' => 'result', 'schema' => ['$ref' => '#/components/schemas/UserDto'], 'description' => 'What was saved'], $save['result']);
Assert::same(
    [['code' => ProtocolError::InvalidParams->value, 'message' => ErrorCase::of(ProtocolError::InvalidParams)->message], ['code' => 1001, 'message' => 'Closed.']],
    $save['errors'],
);
Assert::false(isset($save['x-authorize']), 'public');
Assert::false(isset($save['deprecated']));

$secured = $methods['docs.secured'];
Assert::true($secured['deprecated']);
Assert::true($secured['x-authorize']);
Assert::same([['admin', 'editor']], $secured['x-roles']);
Assert::contains('Requires login and the role admin or editor.', $secured['description']);
Assert::same([], $secured['params']);
Assert::same(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/UserDto']], $secured['result']['schema']);
Assert::same([401, 403], array_column($secured['errors'], 'code'));

Assert::same(['type' => 'null'], $methods['docs.nothing']['result']['schema']);
Assert::same(['name' => 'result', 'schema' => ['type' => ['array', 'object']]], $methods['docs.unread']['result'], 'a docblock is not read for a result');
Assert::false(isset($methods['docs.nothing']['errors']));

$schemas = $document['components']['schemas'];
Assert::same(['AddressDto', 'Role', 'UserDto'], array_keys($schemas));
Assert::same(['name'], $schemas['UserDto']['required']);

// --- rpc.discover

$discover = $dispatcher->handle('api', '{"jsonrpc":"2.0","method":"rpc.discover","id":7}');
Assert::same(7, $discover['id']);
Assert::same($openRpc->toJson('api'), json_encode($discover['result'], JSON_UNESCAPED_SLASHES), 'the very document');
Assert::null($dispatcher->handle('api', '{"jsonrpc":"2.0","method":"rpc.discover"}'), 'a notification gets nothing');

$batch = $dispatcher->handle('api', '[{"jsonrpc":"2.0","method":"rpc.discover","id":1},{"jsonrpc":"2.0","method":"docs.nothing","id":2}]');
Assert::same('1.3.2', $batch[0]['result']->openrpc);
Assert::null($batch[1]['result']);

$hidden = createContainer($config());
Assert::same(
    ProtocolError::MethodNotFound->value,
    $hidden->getByType(Dispatcher::class)->handle('api', '{"jsonrpc":"2.0","method":"rpc.discover","id":1}')['error']['code'],
    'not published in production',
);
Assert::true($hidden->getByType(OpenRpc::class)->has('api'), 'but available to the application');

$debug = createContainer($config() . "\n", debugMode: true);
Assert::same('1.3.2', $debug->getByType(Dispatcher::class)->handle('api', '{"jsonrpc":"2.0","method":"rpc.discover","id":1}')['result']->openrpc);

$off = createContainer($config('{enabled: false, expose: true}'));
Assert::false($off->getByType(OpenRpc::class)->has('api'));
Assert::same(
    ProtocolError::MethodNotFound->value,
    $off->getByType(Dispatcher::class)->handle('api', '{"jsonrpc":"2.0","method":"rpc.discover","id":1}')['error']['code'],
);

// A method of a controller can't get lost behind the document
$e = Assert::exception(static fn() => createContainer($config('{expose: true}', 'DocsClash', 'RpcController')), InvalidStateException::class);
Assert::contains('reserved', $e->getMessage());
Assert::contains('RpcController::discover()', $e->getMessage());

$allowed = createContainer($config('{}', 'DocsClash', 'RpcController'));
Assert::null($allowed->getByType(Dispatcher::class)->handle('api', '{"jsonrpc":"2.0","method":"rpc.discover","id":1}')['error']['data'] ?? null);

// --- DTOs through the dispatcher

$call = static fn(array $params): array => $dispatcher->handle('api', json_encode(['jsonrpc' => '2.0', 'method' => 'docs.save', 'params' => $params, 'id' => 1]));

$saved = $call(['input' => ['name' => 'Ann', 'addresses' => [['city' => 'Brno']], 'ignored' => true]]);
Assert::same(
    '{"name":"Ann","addresses":[{"city":"Brno"}],"role":"admin","created":"2024-01-02T03:04:05+00:00"}',
    json_encode($saved['result']),
);
Assert::same('Ann', $call([['name' => 'Ann']])['result']['name'], 'positional parameters work too');

$invalid = $call(['input' => ['name' => 'A', 'addresses' => [['city' => '']]], 'limit' => 0]);
Assert::same(ProtocolError::InvalidParams->value, $invalid['error']['code']);
Assert::same(
    ['input.name' => 'must have at least 2 characters.', 'input.addresses.0.city' => 'must have at least 1 characters.', 'limit' => 'must be at least 1.'],
    $invalid['error']['fields'],
);

// --- Taking over a request: after the application has started when there is one

$application = $container->getByType(Nette\Application\Application::class);
Assert::count(1, $application->onStartup);
Assert::notContains("rpcApi.server')->process()", (string) preg_replace('~function createServiceApplication.*?\n\t}~s', '', $containerSource));
$application->onStartup[0](); // nothing happens in CLI, and no exit

$withoutApplication = createContainer($config() . "\n\n", withApplication: false);
Assert::false($withoutApplication->hasService('application.application'));
Assert::contains("\$this->getService('rpcApi.server')->process()", $source($withoutApplication));
