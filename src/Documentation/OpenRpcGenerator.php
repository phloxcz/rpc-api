<?php
/**
 * This file is part of phloxcz/rpc-api.
 *
 * (c) Phlox <https://github.com/phloxcz>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phlox\RpcApi\Documentation;

use Phlox\ApiCore\ApiException;
use Phlox\ApiCore\Documentation\DocumentationConfig;
use Phlox\ApiCore\Documentation\MethodReader;
use Phlox\ApiCore\Documentation\SchemaBuilder;
use Phlox\ApiCore\Types\Type;

/**
 * Generates an OpenRPC document from the same method table the server runs on.
 *
 * @phpstan-type HandlerData array{service: string, class: class-string, method: string, authorize: bool, roles: list<list<string>>}
 */
final class OpenRpcGenerator
{
    public const DiscoverMethod = 'rpc.discover';

    private readonly SchemaBuilder $builder;

    public function __construct()
    {
        $this->builder = new SchemaBuilder();
    }

    /**
     * @param array<string, HandlerData> $handlers JSON-RPC method name => handler
     * @return array<string, mixed>
     */
    public function generate(string $endpoint, \stdClass $config, array $handlers): array
    {
        ksort($handlers);

        $methods = [];
        foreach ($handlers as $name => $handler) {
            $methods[] = $this->buildMethod((string) $name, $handler);
        }

        // Unlike OpenAPI, OpenRPC wants an absolute URL, which only the configuration can know
        $servers = $config->servers === [] ? [] : ['servers' => $config->servers];

        return DocumentationConfig::merge([
            'openrpc' => '1.3.2',
            'info' => DocumentationConfig::buildInfo($config->info, $endpoint),
        ] + $servers + [
            'methods' => $methods,
            'components' => ['schemas' => $this->builder->getSchemas() ?: new \stdClass()],
        ], $config->extra);
    }

    /**
     * @return list<class-string>
     */
    public function getClasses(): array
    {
        return $this->builder->getClasses();
    }

    /**
     * @param HandlerData $handler
     * @return array<string, mixed>
     */
    private function buildMethod(string $name, array $handler): array
    {
        $class = new \ReflectionClass($handler['class']);
        $reader = new MethodReader($class, new \ReflectionMethod($handler['class'], $handler['method']), $this->builder);

        $method = ['name' => $name];
        if ($reader->doc->summary !== '') {
            $method['summary'] = $reader->doc->summary;
        }

        $description = array_filter([
            $reader->doc->description,
            $handler['authorize'] ? DocumentationConfig::describeRoles($handler['roles']) : '',
        ]);
        if ($description !== []) {
            $method['description'] = implode("\n\n", $description);
        }

        $tag = preg_replace('/Controller$/', '', $class->getShortName()) ?: $class->getShortName();
        $method['tags'] = [['name' => $tag]];

        if ($reader->deprecated) {
            $method['deprecated'] = true;
        }

        $parameters = $reader->getParameters();

        $method['paramStructure'] = 'either';
        $method['params'] = array_values(array_map(static function (array $info): array {
            $descriptor = ['name' => $info['name'], 'required' => $info['required'], 'schema' => $info['schema']];
            return $info['description'] === '' ? $descriptor : $descriptor + ['description' => $info['description']];
        }, $parameters));

        $return = $reader->getReturnType();
        $method['result'] = ['name' => 'result', 'schema' => $reader->getDescribedResponse()
            ?? ($return->kind === Type::Void ? ['type' => 'null'] : $this->builder->build($return))];
        if ($reader->getReturnDescription() !== '') {
            $method['result']['description'] = $reader->getReturnDescription();
        }

        $errors = $this->buildErrors($reader, $handler, $parameters !== []);
        if ($errors !== []) {
            $method['errors'] = $errors;
        }

        if ($handler['authorize']) {
            $method['x-authorize'] = true;
        }
        if ($handler['roles'] !== []) {
            $method['x-roles'] = $handler['roles'];
        }

        return $method;
    }

    /**
     * @param HandlerData $handler
     * @return list<array{code: int, message: string}>
     */
    private function buildErrors(MethodReader $reader, array $handler, bool $hasParameters): array
    {
        $errors = [];

        if ($handler['authorize']) {
            $errors[401] = 'Not logged in.';
        }
        if ($handler['authorize'] && $handler['roles'] !== []) {
            $errors[403] = 'Missing role.';
        }
        if ($hasParameters) {
            $errors[ApiException::E_INVALID_PARAMS] = ApiException::E_INVALID_PARAMS_TEXT;
        }
        foreach ($reader->getThrows() as $throws) {
            $errors[$throws->code] = $throws->message;
        }

        $result = [];
        foreach ($errors as $code => $message) {
            $result[] = ['code' => $code, 'message' => $message];
        }

        return $result;
    }
}
