<?php

declare(strict_types=1);

namespace Phlox\RpcApi\Tests\Fixtures\Api;

use Phlox\ApiCore\Attributes\AllowAnonymous;
use Phlox\ApiCore\Attributes\Authorize;

/**
 * No attribute on the class: protected by the endpoint default.
 */
class UserController extends BaseController
{
    public int $startupCount = 0;

    public function startup(): void
    {
        parent::startup();
        $this->startupCount++;

        // Authenticates like a token-based API would
        $token = $this->request->getHeader('x-test-role');
        if ($token !== null) {
            $this->user->login(new \Nette\Security\SimpleIdentity(1, [$token]));
        }
    }

    public function read(): string
    {
        return 'user.read';
    }

    #[Authorize(['editor', 'manager'])]
    public function anyRole(): string
    {
        return 'any';
    }

    #[Authorize(['editor', 'manager'], all: true)]
    public function allRoles(): string
    {
        return 'all';
    }

    #[AllowAnonymous]
    public function ping(): string
    {
        return 'pong';
    }

    public function injectSomething(): void
    {
    }

    public static function staticMethod(): string
    {
        return 'must not be callable';
    }
}
