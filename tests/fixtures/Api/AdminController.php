<?php

declare(strict_types=1);

namespace Phlox\RpcApi\Tests\Fixtures\Api;

use Phlox\ApiCore\Attributes\Authorize;

#[Authorize('admin')]
class AdminController extends BaseController
{
    public function secret(int $required): string
    {
        return 'secret';
    }

    #[Authorize('auditor')]
    public function audit(): string
    {
        return 'audit';
    }
}
