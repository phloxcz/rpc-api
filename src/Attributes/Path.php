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

namespace Phlox\RpcApi\Attributes;

use Attribute;

/**
 * Overrides the name a controller or a method has in a JSON-RPC method name.
 *
 * Without it, UserController::read() is "user.read". An empty path on a
 * controller leaves just the method name.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Path
{
    public function __construct(
        public readonly string $path,
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
