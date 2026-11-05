<?php

declare(strict_types=1);

namespace Phlox\RpcApi\Tests\Fixtures\Docs;

use Phlox\ApiCore\ApiController;
use Phlox\ApiCore\Attributes\AllowAnonymous;
use Phlox\ApiCore\Attributes\Authorize;
use Phlox\ApiCore\Attributes\Constraints\Length;
use Phlox\ApiCore\Attributes\Constraints\Range;
use Phlox\ApiCore\Attributes\Dto;
use Phlox\ApiCore\Attributes\Throws;

enum Role: string
{
    case Admin = 'admin';
}

#[Dto]
final readonly class AddressDto
{
    public function __construct(#[Length(min: 1)] public string $city)
    {
    }
}

#[Dto]
final readonly class UserDto
{
    /** @param list<AddressDto> $addresses */
    public function __construct(
        #[Length(min: 2)] public string $name,
        public array $addresses = [],
        public Role $role = Role::Admin,
        public ?\DateTimeImmutable $created = null,
    ) {
    }
}

#[Authorize(['admin', 'editor'])]
class DocsController extends ApiController
{
    /**
     * Saves a user.
     *
     * With details.
     *
     * @param UserDto $input Who
     * @param int $limit How many
     * @return UserDto What was saved
     */
    #[AllowAnonymous]
    #[Throws(1001, 'Closed.')]
    public function save(UserDto $input, #[Range(min: 1)] int $limit = 5): UserDto
    {
        return new UserDto($input->name, $input->addresses, created: new \DateTimeImmutable('2024-01-02T03:04:05+00:00'));
    }

    /**
     * @return list<UserDto>
     * @deprecated
     */
    public function secured(): array
    {
        return [];
    }

    #[AllowAnonymous]
    public function nothing(): void
    {
    }
}
