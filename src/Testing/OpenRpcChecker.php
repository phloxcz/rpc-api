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

namespace Phlox\RpcApi\Testing;

use Phlox\ApiCore\Testing\SchemaValidator;
use Phlox\RpcApi\OpenRpc;

/**
 * Checks results of methods against the OpenRPC document of an endpoint, so tests
 * notice the documentation drifting from what the API returns:
 *
 *     $checker = OpenRpcChecker::of($container->getByType(OpenRpc::class), 'rpc');
 *     Assert::same([], $checker->check('stats.tasks', $response['result']));
 */
final class OpenRpcChecker
{
    /** How many results were checked */
    public int $checked = 0;

    /**
     * @param array<string, mixed> $document
     */
    public function __construct(private readonly array $document)
    {
    }

    public static function of(OpenRpc $openRpc, string $endpoint): self
    {
        /** @var array<string, mixed> $document */
        $document = json_decode($openRpc->toJson($endpoint), true, flags: JSON_THROW_ON_ERROR);

        return new self($document);
    }

    /**
     * @param mixed $result The `result` of a response, decoded as arrays
     * @return list<string> What doesn't match
     */
    public function check(string $method, mixed $result): array
    {
        foreach ($this->document['methods'] ?? [] as $documented) {
            if (($documented['name'] ?? null) === $method) {
                $this->checked++;
                return SchemaValidator::validate($result, $documented['result']['schema'] ?? [], $this->document, "rpc {$method}: \$");
            }
        }

        return ["rpc {$method}: not documented"];
    }
}
