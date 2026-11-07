# phloxcz/rpc-api

**English** | [Česky](README.cs.md)

**[JSON-RPC 2.0](https://www.jsonrpc.org/specification) server for [Nette](https://nette.org).**

API controllers are ordinary DI services. Their methods, authorization and parameters are described by PHP attributes and native types, and the method table is compiled into the DI container, so nothing is reflected at runtime.

> ⚠️ This package is under active development. The API is not yet stable.

## Requirements

PHP 8.4+ and [`phloxcz/api-core`](https://github.com/phloxcz/api-core), which documents controllers, authorization and parameter types.

## Installation

```bash
composer require phloxcz/rpc-api
```

```neon
extensions:
    rpcApi: Phlox\RpcApi\DI\Extension

rpcApi:
    endpoints:
        api:                            # served at /api
            namespace: App\ApiModule    # string or list; includes sub-namespaces
            authorize: true             # default for methods without an attribute
            maxBatchSize: 100
            accessControl:
                allowedOrigins: ['https://app.example.com']
                allowCredentials: true

services:
    - App\ApiModule\UserController
```

## Usage

```php
namespace App\ApiModule;

use Phlox\ApiCore\ApiController;

class UserController extends ApiController
{
    public function read(int $id): array
    {
        return ['id' => $id];
    }
}
```

```
POST /api

{"jsonrpc": "2.0", "method": "user.read", "params": {"id": 1}, "id": 1}
{"jsonrpc": "2.0", "result": {"id": 1}, "id": 1}
```

Parameters can be named or positional. Names must match exactly: `user.read`, not `user/read` or `userread`.

### Method names

`UserController::read()` is `user.read`. `#[Path]` overrides either part, and two methods with the same name on one endpoint fail when the container compiles:

```php
use Phlox\RpcApi\Attributes\Path;

#[Path('admin.user')]
class UserController extends ApiController
{
    #[Path('fetch')]
    public function read(): array { /* admin.user.fetch */ }
}
```

## Protocol

- A request without an `id` member is a notification: it runs, and nothing is sent back, not even an error. `"id": null` is an ordinary request.
- A batch larger than `maxBatchSize` is refused as a whole. Within a batch, a failing call doesn't affect the others.
- Protocol errors use the standard codes `-32700`, `-32600`, `-32601`, `-32602` and `-32603`, see `ProtocolError`. Authorization errors use `401` and `403`.
- An `ApiException` gives its `errorCode`, or its HTTP status without one: `new ApiException(404, 'Not found.')` is code `404`. The status can be left out: `new ApiException(errorCode: 1001, message: 'Closed.')`. `#[Throws]` documents the same code, and takes the same.
- `data` of an `ApiException` is merged straight into the `error` object, next to `code` and `message`.
- The HTTP status is `200` for anything with a body and `204` when there's none.

Requests other than `POST` and `OPTIONS` to an endpoint, and all requests elsewhere, fall through to the application.

## DTOs

A parameter can be a DTO, and a method can return one. See [DTOs](https://github.com/phloxcz/api-core#dtos).

```php
public function save(CreateUser $input): UserOutput { /* ... */ }
```

```json
{"jsonrpc": "2.0", "method": "user.save", "params": {"input": {"name": "Ann"}}, "id": 1}
```

Invalid data gets code `-32602` with all problems in `fields`, such as `input.name`.

## OpenRPC

Every endpoint gets an OpenRPC document, generated when the container compiles from the same methods the server runs on. In debug mode it's published as the method `rpc.discover`, which is how the specification finds it. What it's made of is described in [`phloxcz/api-core`](https://github.com/phloxcz/api-core#documentation).

```neon
rpcApi:
    endpoints:
        api:
            namespace: App\RpcModule
            documentation:
                expose: true        # by default only in debug mode
                info: {title: Orders API, version: 2.1.0}
                servers:            # OpenRPC needs an absolute URL, so it's left out by default
                    - {name: production, url: 'https://api.example.com/api'}
```

The result comes from the native return type, or from `#[QueryOf]`. Where that says too little, such as `array`, `#[Result]` says it, with an object of [`Phlox\ApiCore\Schema`](https://github.com/phloxcz/api-core#describing-a-response). A docblock `@return` isn't read.

```php
use Phlox\ApiCore\Schema\{ListOf, Shape};
use Phlox\RpcApi\Attributes\Result;

#[Result(new Shape(['ids' => new ListOf('int')]), description: 'IDs of the new orders')]
public function import(array $orders): array { /* ... */ }
```

Authorization has no place in OpenRPC, so a method requiring login carries `x-authorize` and `x-roles`, with the same in its description and errors `401` and `403`. While the document is published, a method of your own named `rpc.discover` fails when the container compiles.

### Testing that results match it

`OpenRpcChecker` checks a result against the document, so a test notices the documentation drifting from what the API returns:

```php
use Phlox\RpcApi\Testing\OpenRpcChecker;

$checker = OpenRpcChecker::of($container->getByType(OpenRpc::class), 'rpc');
Assert::same([], $checker->check('stats.tasks', $response['result']));
```

It reports a method the document doesn't have, and every value not matching the schema, such as `rpc stats.tasks: $.data[0].id: "3" is not integer`.

## CORS

Without `allowedOrigins`, no CORS headers are sent. An origin not on the list gets none either. `['*']` sends a literal `*`; combining it with `allowCredentials: true` fails when the container compiles, because browsers refuse it. A client sending an `Authorization` header needs no credentials here; for cookies, list the origins.

## Configuration

| Key | Default | Description |
|---|---|---|
| `debug` | `%debugMode%` | Adds internal messages to error responses |
| `dateTimeFormats` | see `ParameterBinder` | Accepted formats of date parameters |
| `endpoints.<name>.namespace` | required | Controllers served by the endpoint |
| `endpoints.<name>.authorize` | `true` | Whether methods without an attribute require login |
| `endpoints.<name>.maxBatchSize` | `100` | Maximum calls in one batch |
| `endpoints.<name>.accessControl` | no CORS | `allowedOrigins`, `allowCredentials`, `allowMethods`, `allowHeaders`, `exposeHeaders`, `maxAge` |
| `endpoints.<name>.documentation` | generated, published in debug mode | see [OpenRPC](#openrpc) |

## Migrating from abrad450/api-server-nette

The whole way is in [`MIGRATION.md`](https://github.com/phloxcz/api-core/blob/master/MIGRATION.md). What concerns this package:

Namespaces:

| Old | New |
|---|---|
| `AbraD450\ApiServer\ApiController` | `Phlox\ApiCore\ApiController` |
| `AbraD450\ApiServer\Common\Attributes\*` | `Phlox\ApiCore\Attributes\*` |
| `AbraD450\ApiServer\RPC\Attributes\Path` | `Phlox\RpcApi\Attributes\Path` |
| `AbraD450\ApiServer\RPC\ApiException` | `Phlox\ApiCore\ApiException` |
| `AbraD450\ApiServer\ApiConstructible` | `Phlox\ApiCore\ApiConstructible` |

Changes that need checking in an existing project:

- **Config:** `apiServer: rpc: <name>` becomes `rpcApi: endpoints: <name>`.
- **Authorization is on by default.** Mark public methods with `#[AllowAnonymous]`, or set `authorize: false` on the endpoint.
- **`#[Authorize(['a', 'b'])]` now means a or b, so it lets in more users than before.** Where both roles are needed, write `#[Authorize(['a', 'b'], all: true)]`. Attributes on a class and a method still have to hold together.
- **Inherited methods are no longer exposed.** A method from a shared base controller has to be overridden in the concrete controller.
- **`#[NonApi]` now works in RPC.** Methods carrying it were callable before.
- **CORS:** `allowedOrigins: ['*']` with `allowCredentials: true` must become a list of origins.
- **Method names match exactly.** Variants such as `user/read` no longer resolve.
- **Stricter parameters:** `"1.5"` for `int` and `true` for `string` are refused.
- **Stricter protocol:** failed notifications no longer send an error, and `"id": null` is no longer a notification.
- **The bootstrap runs before the API.** Code after `createContainer()` now also runs for API requests, including anything like a redirect or a maintenance mode. `index.php` has to call `Application::run()`.
- **Dates and times:** a time zone alone, such as `x` or `utc`, is now refused instead of meaning the current time.
- **Results are described by `#[Result]`,** not by `@return`. A method returning `array` without it is documented as any array, see [OpenRPC](#openrpc).
- **The query language** moved to [`phloxcz/api-query`](https://github.com/phloxcz/api-query#migrating-from-abrad450api-server-nette), including `$this->processor`.

## License

[MIT](LICENSE)
