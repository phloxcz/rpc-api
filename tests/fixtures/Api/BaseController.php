<?php

declare(strict_types=1);

namespace Phlox\RpcApi\Tests\Fixtures\Api;

use Phlox\ApiCore\ApiController;

abstract class BaseController extends ApiController
{
    public function inheritedHelper(): string
    {
        return 'must not be callable';
    }
}
