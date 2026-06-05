<?php

namespace Database\Factories;

use App\Models\ApiRequestLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiRequestLog>
 */
class ApiRequestLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'endpoint' => fake()->randomElement([
                '/api/users',
                '/api/posts',
                '/api/comments',
                '/api/weather/current',
                '/api/news/headlines',
            ]),
            'method' => fake()->randomElement(['GET', 'POST', 'PUT', 'DELETE', 'PATCH']),
            'status_code' => fake()->randomElement([200, 201, 400, 404, 500, 503]),
            'ip_address' => fake()->ipv4(),
            'requested_at' => fake()->dateTime(),
        ];
    }

    /**
     * Indicate that the request was successful (200 status code).
     */
    public function successful(): static
    {
        return $this->state(fn(array $attributes) => [
            'status_code' => 200,
        ]);
    }

    /**
     * Indicate that the request was created (201 status code).
     */
    public function created(): static
    {
        return $this->state(fn(array $attributes) => [
            'status_code' => 201,
        ]);
    }

    /**
     * Indicate that the request had an error (4xx or 5xx status code).
     */
    public function error(): static
    {
        return $this->state(fn(array $attributes) => [
            'status_code' => fake()->randomElement([400, 401, 403, 404, 500, 502, 503]),
        ]);
    }

    /**
     * Set a specific endpoint.
     */
    public function forEndpoint(string $endpoint): static
    {
        return $this->state(fn(array $attributes) => [
            'endpoint' => $endpoint,
        ]);
    }

    /**
     * Set a specific HTTP method.
     */
    public function withMethod(string $method): static
    {
        return $this->state(fn(array $attributes) => [
            'method' => $method,
        ]);
    }

    /**
     * Set the requested_at timestamp.
     */
    public function requestedAt($timestamp): static
    {
        return $this->state(fn(array $attributes) => [
            'requested_at' => $timestamp,
        ]);
    }
}
