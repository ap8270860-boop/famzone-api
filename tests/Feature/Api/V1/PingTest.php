<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class PingTest extends TestCase
{
    public function test_ping_endpoint_responds_at_the_versioned_url(): void
    {
        $response = $this->getJson('/api/v1/ping');

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'pong',
                'data' => [
                    'api_version' => 'v1',
                ],
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'app',
                    'environment',
                    'api_version',
                    'laravel',
                    'php',
                    'server_time',
                ],
            ]);
    }

    public function test_unversioned_ping_is_not_routable(): void
    {
        $this->getJson('/api/ping')->assertNotFound();
    }
}
