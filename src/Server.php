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

use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Phlox\ApiCore\ApiException;
use Phlox\ApiCore\ErrorReporter;
use Phlox\ApiCore\Http\CorsHandler;

/**
 * HTTP side of the JSON-RPC API: recognizes requests to an endpoint and sends
 * the response.
 *
 * @phpstan-import-type Response from Dispatcher
 * @phpstan-type AccessControl array{allowedOrigins: list<string>, allowCredentials: bool, allowMethods: list<string>, allowHeaders: list<string>, exposeHeaders: list<string>, maxAge: int}
 */
final class Server
{
    /**
     * @param array<string, AccessControl> $accessControl Endpoint name => CORS config
     */
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly CorsHandler $cors,
        private readonly IRequest $request,
        private readonly IResponse $response,
        private readonly ErrorReporter $errorReporter,
        private readonly array $accessControl,
    ) {
    }

    /**
     * @return bool Whether the request was handled, i.e. was aimed at an endpoint
     */
    public function process(): bool
    {
        $method = $this->request->getMethod();
        if ($method !== IRequest::Post && $method !== 'OPTIONS') {
            return false;
        }

        $url = $this->request->getUrl();
        $endpoint = trim(substr($url->getPath(), strlen($url->getBasePath())), '/');
        if (!isset($this->accessControl[$endpoint])) {
            return false;
        }

        $this->cors->apply($this->accessControl[$endpoint]);

        if ($method === 'OPTIONS') {
            $this->response->setCode(IResponse::S204_NoContent);
            return true;
        }

        $output = $this->encode($this->dispatcher->handle($endpoint, $this->request->getRawBody() ?? ''));

        if ($output === null) {
            $this->response->setCode(IResponse::S204_NoContent);
            return true;
        }

        $this->response->setCode(IResponse::S200_OK);
        $this->response->setContentType('application/json', 'utf-8');
        echo $output;

        return true;
    }

    /**
     * @param Response|list<Response>|null $structure
     */
    private function encode(?array $structure): ?string
    {
        if ($structure === null) {
            return null;
        }

        try {
            return Json::encode($structure);
        } catch (JsonException $e) {
            // A controller returned something unencodable, e.g. malformed UTF-8
            $this->errorReporter->report($e);

            return Json::encode([
                'jsonrpc' => Dispatcher::JsonRpcVersion,
                'error' => ['code' => ApiException::E_INTERNAL_ERROR, 'message' => ApiException::E_INTERNAL_ERROR_TEXT],
                'id' => null,
            ]);
        }
    }
}
