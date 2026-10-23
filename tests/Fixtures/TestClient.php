<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests\Fixtures;

use Dmorenof\SdkRuntime\ApiClient;

final class TestClient extends ApiClient
{
    protected const array SECURITY_SCHEMES = [
        'Bearer' => ['type' => 'http', 'scheme' => 'bearer', 'location' => null, 'parameterName' => null],
    ];

    private const array OPERATION = [
        'label' => 'GET /users/{id}',
        'method' => 'GET',
        'path' => '/users/{id}',
        'security' => [['Bearer' => []]],
        'success' => [200],
        'errors' => [404 => Address::class],
        'responseType' => User::class,
        'schemaNullable' => false,
        'noContentAllowed' => false,
        'validation' => ['id' => ['minimum' => 1]],
    ];

    private const array CREATE = [
        'label' => 'POST /users',
        'method' => 'POST',
        'path' => '/users',
        'security' => [['Bearer' => []]],
        'success' => [200],
        'errors' => [],
        'responseType' => User::class,
        'schemaNullable' => false,
        'noContentAllowed' => false,
        'validation' => [],
    ];

    public function getUser(int $id, ?bool $verbose = null): User
    {
        return $this->executeOperation(self::OPERATION, ['id' => $id], ['verbose' => $verbose]);
    }

    public function createUser(User $user): User
    {
        return $this->executeOperation(self::CREATE, body: $user);
    }
}
