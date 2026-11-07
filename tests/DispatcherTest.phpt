<?php

/**
 * Protocol, exposure and authorization rules, through a real compiled container.
 */

declare(strict_types=1);

use Phlox\ApiCore\ProtocolError;
use Phlox\ApiCore\Attributes\ErrorCase;
use Nette\Security\SimpleIdentity;
use Nette\Security\User;
use Phlox\ApiCore\ApiException;
use Phlox\RpcApi\Dispatcher;
use Phlox\RpcApi\Tests\Fixtures\Api\PublicController;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$container = createContainer(StandardConfig);
$dispatcher = $container->getByType(Dispatcher::class);

$call = static fn(string $method, array $params = [], mixed $id = 1): ?array => $dispatcher->handle('api', json_encode(
    ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => $id],
));
$errorCode = static fn(?array $response): mixed => $response['error']['code'] ?? 'no error';

// --- Result envelope and parameters

Assert::same(['jsonrpc' => '2.0', 'result' => 7, 'id' => 1], $call('public.add', ['a' => 3, 'b' => 4]));
Assert::same(7, $call('public.add', [3, 4])['result'], 'positional parameters');
Assert::same(13, $call('public.add', ['a' => 3])['result'], 'default value');
Assert::same('abc', $call('public.currentMethod', [], 'abc')['id'], 'string id');
Assert::same(ProtocolError::InvalidParams->value, $errorCode($call('public.add', ['a' => 'x'])));
Assert::same(ProtocolError::InvalidParams->value, $errorCode($call('public.add', [])));

// --- Protocol errors

Assert::same(ProtocolError::ParseError->value, $errorCode($dispatcher->handle('api', '{not json')));
Assert::same(ProtocolError::ParseError->value, $errorCode($dispatcher->handle('api', '')));
Assert::same(ProtocolError::InvalidRequest->value, $errorCode($dispatcher->handle('api', '[]')));
Assert::same(ProtocolError::InvalidRequest->value, $errorCode($dispatcher->handle('api', '5')));
Assert::same(ProtocolError::InvalidRequest->value, $errorCode($dispatcher->handle('api', '{"method":"public.add","id":1}')));
Assert::same(ProtocolError::InvalidRequest->value, $errorCode($dispatcher->handle('api', '{"jsonrpc":"1.0","method":"public.add","id":1}')));
Assert::same(ProtocolError::InvalidRequest->value, $errorCode($dispatcher->handle('api', '{"jsonrpc":"2.0","method":"public.add","params":5,"id":1}')));
Assert::same(ProtocolError::InvalidRequest->value, $errorCode($dispatcher->handle('api', '{"jsonrpc":"2.0","method":"public.add","id":{"a":1}}')));

// A present null id is a request, not a notification
Assert::same(
    ['jsonrpc' => '2.0', 'result' => 11, 'id' => null],
    $dispatcher->handle('api', '{"jsonrpc":"2.0","method":"public.add","params":{"a":1},"id":null}'),
);

// --- Method names resolve exactly, without the old prefix matching

Assert::same('renamed', $call('custom.path.go')['result']);
foreach (['publicadd', 'public/add', 'public..add', 'public.Add', 'nonsense', 'rpc.discover'] as $name) {
    Assert::same(ProtocolError::MethodNotFound->value, $errorCode($call($name, ['a' => 1])), $name);
}

// --- Exposure

$notCallable = [
    'public.hidden' => '#[NonApi]',
    'public.startup' => 'startup',
    'public.terminate' => 'inherited from ApiController',
    'public.setParameters' => 'inherited from ApiController',
    'public.injectPrimary' => 'inherited from ApiController',
    'user.inheritedHelper' => 'inherited from own base controller',
    'user.injectSomething' => 'inject method',
    'user.staticMethod' => 'static',
    'other.read' => 'namespace with the same prefix',
];
foreach ($notCallable as $name => $reason) {
    Assert::same(ProtocolError::MethodNotFound->value, $errorCode($call($name)), "$name: $reason");
}

// --- Errors don't leak internals outside debug mode

$failure = $call('public.fail');
Assert::same(1234, $failure['error']['code'], 'data can\'t overwrite the code');
Assert::same('Public failure', $failure['error']['message']);
Assert::same('name', $failure['error']['field']);

$crash = $call('public.crash');
Assert::same(ProtocolError::InternalError->value, $crash['error']['code']);
Assert::same(ErrorCase::of(ProtocolError::InternalError)->message, $crash['error']['message']);
Assert::same(1, $crash['id']);

// --- terminate() and notifications produce nothing

Assert::null($call('public.abort'));

$publicController = $container->getByType(PublicController::class);
Assert::null($dispatcher->handle('api', '{"jsonrpc":"2.0","method":"public.note","params":{"text":"a"}}'));
Assert::same(['a'], $publicController->notified, 'notification is executed');
Assert::null($dispatcher->handle('api', '{"jsonrpc":"2.0","method":"public.crash"}'), 'failed notification stays silent');
Assert::null($dispatcher->handle('api', '{"jsonrpc":"2.0","method":"does.notExist"}'));

// --- Batch

$batch = $dispatcher->handle('api', json_encode([
    ['jsonrpc' => '2.0', 'method' => 'public.currentMethod', 'id' => 1],
    ['jsonrpc' => '2.0', 'method' => 'public.note', 'params' => ['text' => 'b']],
    ['jsonrpc' => '2.0', 'method' => 'public.crash', 'id' => 2],
    ['jsonrpc' => '2.0', 'method' => 'public.otherMethod', 'id' => 3],
    5,
]));
Assert::count(4, $batch, 'notification has no response; invalid item has one');
Assert::same('currentMethod', $batch[0]['result']);
Assert::same(ProtocolError::InternalError->value, $batch[1]['error']['code'], 'crash keeps its id');
Assert::same(2, $batch[1]['id']);
Assert::same('otherMethod', $batch[2]['result'], 'parameters are refreshed for each call on the same controller');
Assert::same(ProtocolError::InvalidRequest->value, $batch[3]['error']['code']);
Assert::null($batch[3]['id']);

Assert::null($dispatcher->handle('api', json_encode([
    ['jsonrpc' => '2.0', 'method' => 'public.note', 'params' => ['text' => 'c']],
])), 'batch of notifications only');

$tooLarge = array_fill(0, 6, ['jsonrpc' => '2.0', 'method' => 'public.add', 'params' => ['a' => 1], 'id' => 1]);
Assert::same(ProtocolError::InvalidRequest->value, $errorCode($dispatcher->handle('api', json_encode($tooLarge))));

// --- Authorization

// Default is on: no attribute at all still requires login
Assert::same(401, $errorCode($call('user.read')));
Assert::same('pong', $call('user.ping')['result'], '#[AllowAnonymous] on a method');
Assert::same(401, $errorCode($call('public.needsLogin')), '#[Authorize] beats class-level #[AllowAnonymous]');

// Anonymous gets 401 and no information about parameters
Assert::same(401, $errorCode($call('admin.secret', [])));
Assert::same(401, $errorCode($call('admin.audit')));

$user = $container->getByType(User::class);

$user->login(new SimpleIdentity(1, ['editor']));
Assert::same('user.read', $call('user.read')['result']);
Assert::same(403, $errorCode($call('admin.secret', ['required' => 1])), 'missing class role');
Assert::contains('Forbidden', $call('admin.secret', ['required' => 1])['error']['message']);
Assert::notContains('admin', $call('admin.secret', ['required' => 1])['error']['message'], 'roles are internal');

$user->login(new SimpleIdentity(1, ['admin']));
Assert::same('secret', $call('admin.secret', ['required' => 1])['result']);
Assert::same(ProtocolError::InvalidParams->value, $errorCode($call('admin.secret', [])), 'authorized client sees parameter errors');
Assert::same(403, $errorCode($call('admin.audit')), 'method and class roles add up');

$user->login(new SimpleIdentity(1, ['admin', 'auditor']));
Assert::same('audit', $call('admin.audit')['result']);

$user->login(new SimpleIdentity(1, ['manager']));
Assert::same('any', $call('user.anyRole')['result'], 'one role of the attribute is enough');
Assert::same(403, $errorCode($call('user.allRoles')), 'all: true needs every role');

$user->login(new SimpleIdentity(1, ['editor', 'manager']));
Assert::same('all', $call('user.allRoles')['result']);

$user->login(new SimpleIdentity(1, ['reader']));
Assert::same(403, $errorCode($call('user.anyRole')));

// --- Startup runs once per request

$user->logout(true);
$userController = $container->getByType(Phlox\RpcApi\Tests\Fixtures\Api\UserController::class);
$before = $userController->startupCount;
$dispatcher->handle('api', json_encode([
    ['jsonrpc' => '2.0', 'method' => 'user.ping', 'id' => 1],
    ['jsonrpc' => '2.0', 'method' => 'user.ping', 'id' => 2],
]));
Assert::same($before, $userController->startupCount, 'already called during earlier calls in this process');

// --- Unknown endpoint is a programmer error

Assert::exception(
    static fn() => $dispatcher->handle('nope', '{}'),
    InvalidArgumentException::class,
);
