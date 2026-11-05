<?php

declare(strict_types=1);

namespace Phlox\RpcApi\Tests\Fixtures\Api;

use Phlox\ApiCore\ApiController;
use Phlox\ApiCore\Attributes\AllowAnonymous;
use Phlox\RpcApi\Attributes\Path;

#[Path('custom.path')]
#[AllowAnonymous]
class RenamedController extends ApiController
{
    #[Path('go')]
    public function someLongName(): string
    {
        return 'renamed';
    }
}
