<?php

declare(strict_types=1);

namespace Phlox\RpcApi\Tests\Fixtures\DuplicateNames;

use Phlox\ApiCore\ApiController;
use Phlox\RpcApi\Attributes\Path;

#[Path('same')]
class AController extends ApiController
{
    public function read(): void
    {
    }
}
