<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests\Fixtures;

use Dmorenof\SdkRuntime\ApiClient;

final class TestClient extends ApiClient
{
    protected const array SECURITY_SCHEMES = [
        'Bearer' => ['type' => 'http', 'scheme' => 'bearer', 'location' => null, 'parameterName' => null],
    ];

    private const array GET_USER = [
        'method' => 'GET',
        'path' => '/users/{id}',
        'security' => [['Bearer' => []]],
        'errors' => [404 => Address::class],
        'responseType' => User::class,
        'validation' => ['id' => ['minimum' => 1]],
    ];

    private const array CREATE_USER = [
        'method' => 'POST',
        'path' => '/users',
        'security' => [['Bearer' => []]],
        'responseType' => User::class,
    ];

    // Declares nothing but method and path: everything else is inherited.
    private const array PING = ['method' => 'GET', 'path' => '/ping'];

    private const array DELETE_USER = [
        'method' => 'DELETE',
        'path' => '/users/{id}',
        'success' => [204],
        'noContentAllowed' => true,
        'responseType' => User::class,
    ];

    public function getUser(int $id, ?bool $verbose = null): User
    {
        return $this->executeOperation(self::GET_USER, ['id' => $id], ['verbose' => $verbose]);
    }

    public function createUser(User $user): User
    {
        return $this->executeOperation(self::CREATE_USER, body: $user);
    }

    public function ping(): void
    {
        $this->executeOperation(self::PING);
    }

    public function deleteUser(int $id): ?User
    {
        return $this->executeOperation(self::DELETE_USER, ['id' => $id]);
    }
}
