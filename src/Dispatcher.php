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

namespace Phlox\RpcApi;

use Phlox\ApiCore\ProtocolError;
use Phlox\ApiCore\AbortException;
use Phlox\ApiCore\ApiException;
use Phlox\ApiCore\ControllerInvoker;
use Phlox\ApiCore\ErrorReporter;
use Phlox\ApiCore\Handler;
use Phlox\RpcApi\Documentation\OpenRpcGenerator;

/**
 * JSON-RPC 2.0 protocol: turns a raw request body into a response structure.
 *
 * Knows nothing about HTTP and never exits, see Server for that. No exception
 * leaves it, so one failing call can't take down the rest of a batch.
 *
 * @phpstan-type Response array<string, mixed>
 * @phpstan-type HandlerData array{service: string, class: class-string<\Phlox\ApiCore\ApiController>, method: string, authorize: bool, roles: list<list<string>>}
 * @phpstan-type Endpoint array{maxBatchSize: int, discover: bool, handlers: array<string, HandlerData>}
 */
final class Dispatcher
{
    public const JsonRpcVersion = '2.0';

    /**
     * @param array<string, Endpoint> $endpoints
     */
    public function __construct(
        private readonly ControllerInvoker $invoker,
        private readonly ErrorReporter $errorReporter,
        private readonly OpenRpc $documents,
        private readonly array $endpoints,
        private readonly bool $debugMode = false,
    ) {
    }

    /**
     * @return Response|list<Response>|null Null when there's nothing to send (only notifications)
     */
    public function handle(string $endpoint, string $body): ?array
    {
        $config = $this->endpoints[$endpoint]
            ?? throw new \InvalidArgumentException("Unknown endpoint '{$endpoint}'.");

        try {
            $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->buildError(new ApiException(ProtocolError::ParseError, internal: $e->getMessage()), null);
        }

        if (!is_array($request) || $request === []) {
            return $this->buildError(new ApiException(ProtocolError::InvalidRequest), null);
        }

        if (!array_is_list($request)) {
            return $this->processCall($endpoint, $config, $request);
        }

        if (count($request) > $config['maxBatchSize']) {
            return $this->buildError(new ApiException(
                ProtocolError::InvalidRequest,
                "Batch is too large, at most {$config['maxBatchSize']} calls are allowed.",
            ), null);
        }

        $responses = [];
        foreach ($request as $call) {
            $response = $this->processCall($endpoint, $config, $call);
            if ($response !== null) {
                $responses[] = $response;
            }
        }

        return $responses === [] ? null : $responses;
    }

    /**
     * @param Endpoint $config
     * @return Response|null
     */
    private function processCall(string $endpoint, array $config, mixed $call): ?array
    {
        if (!is_array($call)) {
            return $this->buildError(new ApiException(ProtocolError::InvalidRequest), null);
        }

        $isNotification = !array_key_exists('id', $call);
        $id = $call['id'] ?? null;

        if (!is_string($id) && !is_int($id) && !is_float($id) && $id !== null) {
            return $this->buildError(new ApiException(ProtocolError::InvalidRequest), null);
        }

        $params = $call['params'] ?? [];
        $method = $call['method'] ?? null;

        if (($call['jsonrpc'] ?? null) !== self::JsonRpcVersion || !is_string($method) || !is_array($params)) {
            return $this->buildError(new ApiException(ProtocolError::InvalidRequest), $id);
        }

        try {
            if ($method === OpenRpcGenerator::DiscoverMethod && $config['discover']) {
                return $isNotification ? null : [
                    'jsonrpc' => self::JsonRpcVersion,
                    'result' => $this->documents->toObject($endpoint),
                    'id' => $id,
                ];
            }

            $handler = $config['handlers'][$method] ?? throw new ApiException(
                ProtocolError::MethodNotFound,
                internal: "No controller method is registered for '{$method}'.",
            );

            $result = $this->invoker->invoke(Handler::fromArray($handler), $params, $params);
        } catch (AbortException) {
            return null;
        } catch (ApiException $e) {
            return $isNotification ? null : $this->buildError($e, $id);
        } catch (\Throwable $e) {
            $this->errorReporter->report($e);
            return $isNotification ? null : $this->buildError(new ApiException(
                ProtocolError::InternalError,
                internal: $e->getMessage(),
            ), $id);
        }

        return $isNotification ? null : [
            'jsonrpc' => self::JsonRpcVersion,
            'result' => $result,
            'id' => $id,
        ];
    }

    /**
     * @return Response
     */
    private function buildError(ApiException $e, string|int|float|null $id): array
    {
        $message = $e->getMessage();
        if ($this->debugMode && $e->getInternalMessage() !== null) {
            $message .= ' ' . $e->getInternalMessage();
        }

        return [
            'jsonrpc' => self::JsonRpcVersion,
            'error' => [...$e->getData(), 'code' => $e->getCode(), 'message' => $message],
            'id' => $id,
        ];
    }
}
