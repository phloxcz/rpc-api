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

namespace Phlox\RpcApi\DI;

use Nette;
use Nette\Schema\Expect;
use Phlox\ApiCore\ApiController;
use Phlox\ApiCore\Binding\ParameterBinder;
use Phlox\ApiCore\ControllerInvoker;
use Phlox\ApiCore\DI\ControllerScanner;
use Phlox\ApiCore\DI\ServerStartup;
use Phlox\ApiCore\Documentation\DocumentationConfig;
use Phlox\ApiCore\ErrorReporter;
use Phlox\ApiCore\Http\CorsHandler;
use Phlox\ApiCore\Security\Authorizer;
use Phlox\RpcApi\Attributes\Path;
use Phlox\RpcApi\Dispatcher;
use Phlox\RpcApi\Documentation\OpenRpcGenerator;
use Phlox\RpcApi\OpenRpc;
use Phlox\RpcApi\Server;
use ReflectionClass;
use ReflectionMethod;

/**
 * Registers the JSON-RPC server. Controllers are ordinary services; the method
 * table is built from them when the container compiles.
 *
 * @property-read \stdClass $config
 */
final class Extension extends Nette\DI\CompilerExtension
{
    private bool $hookedToApplication = false;

    public function getConfigSchema(): Nette\Schema\Schema
    {
        return Expect::structure([
            'debug' => Expect::bool()->nullable(),
            'dateTimeFormats' => Expect::listOf('string')->default(ParameterBinder::DefaultDateTimeFormats),
            'endpoints' => Expect::arrayOf(
                Expect::structure([
                    'namespace' => Expect::anyOf(Expect::string(), Expect::listOf('string'))
                        ->required()
                        ->before(static fn(mixed $value): array => (array) $value),
                    'authorize' => Expect::bool(true),
                    'maxBatchSize' => Expect::int(100)->min(1),
                    'accessControl' => CorsHandler::getConfigSchema(['POST', 'OPTIONS']),
                    'documentation' => DocumentationConfig::getSchema(),
                ]),
                'string',
            )->required(),
        ]);
    }

    public function loadConfiguration(): void
    {
        $builder = $this->getContainerBuilder();

        foreach ($this->config->endpoints as $name => $endpoint) {
            CorsHandler::assertValidConfig($endpoint->accessControl, $name);
        }

        // Not autowired, so the REST library can live in the same container
        $binder = $builder->addDefinition($this->prefix('binder'))
            ->setFactory(ParameterBinder::class, [$this->config->dateTimeFormats])
            ->setAutowired(false);

        $authorizer = $builder->addDefinition($this->prefix('authorizer'))
            ->setFactory(Authorizer::class)
            ->setAutowired(false);

        $invoker = $builder->addDefinition($this->prefix('invoker'))
            ->setFactory(ControllerInvoker::class, ['binder' => $binder, 'authorizer' => $authorizer])
            ->setAutowired(false);

        $cors = $builder->addDefinition($this->prefix('cors'))
            ->setFactory(CorsHandler::class)
            ->setAutowired(false);

        $errorReporter = $builder->addDefinition($this->prefix('errorReporter'))
            ->setFactory(ErrorReporter::class)
            ->setAutowired(false);

        // Documents and the method table are filled in beforeCompile(), once controllers are registered
        $documents = $builder->addDefinition($this->prefix('openRpc'))
            ->setFactory(OpenRpc::class);

        $dispatcher = $builder->addDefinition($this->prefix('dispatcher'))
            ->setFactory(Dispatcher::class, [
                'invoker' => $invoker,
                'errorReporter' => $errorReporter,
                'documents' => $documents,
                'debugMode' => $this->config->debug ?? $builder->parameters['debugMode'] ?? false,
            ]);

        $builder->addDefinition($this->prefix('server'))
            ->setFactory(Server::class, [
                'dispatcher' => $dispatcher,
                'cors' => $cors,
                'errorReporter' => $errorReporter,
                'accessControl' => array_map(
                    static fn(\stdClass $endpoint): array => $endpoint->accessControl,
                    $this->config->endpoints,
                ),
            ]);
    }

    public function beforeCompile(): void
    {
        $builder = $this->getContainerBuilder();

        $endpoints = [];
        $documents = [];
        foreach ($this->config->endpoints as $name => $endpoint) {
            $handlers = $this->buildHandlers($builder, (string) $name, $endpoint);
            $exposed = false;

            if ($endpoint->documentation->enabled) {
                $generator = new OpenRpcGenerator();
                $documents[$name] = Nette\Utils\Json::encode($generator->generate((string) $name, $endpoint->documentation, $handlers));

                // A changed DTO recompiles the container in debug mode, as a changed controller does
                foreach ($generator->getClasses() as $class) {
                    $builder->addDependency(new ReflectionClass($class));
                }

                $exposed = (bool) ($endpoint->documentation->expose ?? $builder->parameters['debugMode'] ?? false);

                if ($exposed && isset($handlers[OpenRpcGenerator::DiscoverMethod])) {
                    throw new Nette\InvalidStateException(
                        "Endpoint '{$name}': " . OpenRpcGenerator::DiscoverMethod . ' is reserved for the OpenRPC document, '
                        . "but {$handlers[OpenRpcGenerator::DiscoverMethod]['class']}::{$handlers[OpenRpcGenerator::DiscoverMethod]['method']}() uses it.",
                    );
                }
            }

            $endpoints[$name] = [
                'maxBatchSize' => $endpoint->maxBatchSize,
                'discover' => $exposed,
                'handlers' => $handlers,
            ];
        }

        /** @var Nette\DI\Definitions\ServiceDefinition $dispatcher */
        $dispatcher = $builder->getDefinition($this->prefix('dispatcher'));
        $dispatcher->setArgument('endpoints', $endpoints);

        /** @var Nette\DI\Definitions\ServiceDefinition $openRpc */
        $openRpc = $builder->getDefinition($this->prefix('openRpc'));
        $openRpc->setArgument('documents', $documents);

        $this->hookedToApplication = ServerStartup::hookApplication($builder, $this->prefix('server'));
    }

    public function afterCompile(Nette\PhpGenerator\ClassType $class): void
    {
        if (!$this->hookedToApplication) {
            ServerStartup::hookContainer($class, $this->prefix('server'));
        }
    }

    /**
     * @return array<string, mixed> JSON-RPC method name => handler data
     */
    private function buildHandlers(Nette\DI\ContainerBuilder $builder, string $endpointName, \stdClass $endpoint): array
    {
        $handlers = [];

        foreach (ControllerScanner::findControllers($builder, $endpoint->namespace) as $service => $class) {
            foreach (ControllerScanner::getApiMethods($class) as $method) {
                $rpcName = self::buildMethodName($class, $method);

                if (isset($handlers[$rpcName])) {
                    throw new Nette\InvalidStateException(
                        "Endpoint '{$endpointName}': JSON-RPC method '{$rpcName}' is defined by both "
                        . "{$handlers[$rpcName]['class']}::{$handlers[$rpcName]['method']}() and "
                        . "{$class->getName()}::{$method->getName()}().",
                    );
                }

                $handlers[$rpcName] = ControllerScanner::createHandler(
                    $service,
                    $class,
                    $method,
                    $endpoint->authorize,
                )->toArray();
            }
        }

        return $handlers;
    }

    /**
     * @param ReflectionClass<ApiController> $class
     */
    private static function buildMethodName(ReflectionClass $class, ReflectionMethod $method): string
    {
        $controllerPath = self::getPath($class)
            ?? lcfirst(preg_replace('/Controller$/', '', $class->getShortName()) ?? '');
        $methodPath = self::getPath($method) ?? lcfirst($method->getName());

        return implode('.', array_filter([$controllerPath, $methodPath], static fn(string $part): bool => $part !== ''));
    }

    /**
     * @param ReflectionClass<ApiController>|ReflectionMethod $reflection
     */
    private static function getPath(ReflectionClass|ReflectionMethod $reflection): ?string
    {
        $attributes = $reflection->getAttributes(Path::class);

        return $attributes === [] ? null : $attributes[0]->newInstance()->path;
    }
}
