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
use Phlox\ApiCore\Schema\SchemaDefinition;

/**
 * Documents the result of a method, where the native return type can't tell it:
 *
 *     #[Result(new ListOf(User::class), description: 'The users')]
 *     #[Result(new Shape(['ids' => new ListOf('int')]))]
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Result
{
    /**
     * @param SchemaDefinition|string|null $content An object from Phlox\ApiCore\Schema, a class as `User::class`, or a scalar
     *        as `'int'`; without it, the return type or #[QueryOf] tells it
     */
    public function __construct(
        public readonly SchemaDefinition|string|null $content = null,
        public readonly ?string $description = null,
    ) {
    }
}
