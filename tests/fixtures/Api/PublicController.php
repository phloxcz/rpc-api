<?php

declare(strict_types=1);

namespace Phlox\RpcApi\Tests\Fixtures\Api;

use Phlox\ApiCore\ApiController;
use Phlox\ApiCore\ApiException;
use Phlox\ApiCore\Attributes\AllowAnonymous;
use Phlox\ApiCore\Attributes\Authorize;
use Phlox\ApiCore\Attributes\NonApi;

#[AllowAnonymous]
class PublicController extends ApiController
{
    /** @var list<string> */
    public array $notified = [];

    public function add(int $a, int $b = 10): int
    {
        return $a + $b;
    }

    public function currentMethod(): string
    {
        return $this->methodName;
    }

    public function otherMethod(): string
    {
        return $this->methodName;
    }

    public function note(string $text): void
    {
        $this->notified[] = $text;
    }

    public function fail(): never
    {
        throw new ApiException(1234, 'Public failure', 'secret detail', data: ['field' => 'name', 'code' => 'spoofed']);
    }

    public function crash(): never
    {
        throw new \RuntimeException('secret crash detail');
    }

    public function abort(): void
    {
        $this->terminate();
    }

    #[NonApi]
    public function hidden(): string
    {
        return 'must not be callable';
    }

    #[Authorize]
    public function needsLogin(): string
    {
        return 'logged in';
    }
}
