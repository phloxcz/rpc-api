<?php

declare(strict_types=1);

namespace Phlox\RpcApi\Tests\Fixtures\ApiOther;

use Phlox\ApiCore\ApiController;
use Phlox\ApiCore\Attributes\AllowAnonymous;

/**
 * Its namespace merely starts with the same text as the endpoint's namespace.
 */
#[AllowAnonymous]
class OtherController extends ApiController
{
    public function read(): string
    {
        return 'must not be reachable from the Api endpoint';
    }
}
