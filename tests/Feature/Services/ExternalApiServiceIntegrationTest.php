<?php

namespace Tests\Feature\Services;

use App\Services\ExternalApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExternalApiServiceIntegrationTest extends TestCase
{
    /**
     * The service instance for testing.
     */
    protected ExternalApiService $service;

    /**
     * Set up the test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ExternalApiService::class);

        // Use array cache for testing (in-memory)
        Config::set('cache.default', 'array');
        Cache::flush();
    }

    /**
     * Test cache hit/miss behavior with real cache backend.
     */
    #[Test]
    public function testCacheHitAndMissBehavior(): void
    {
        Config::set('apicache.ttl', 3600);
        Config::set('apicache.timeout', 10);
        Config::set('apicache.external_apis', [
            'weather' => [
                'base_url' => 'https://api.weather.example.com',
                'api_key' => 'test-key',
            ],
        ]);

        $endpoint = 'weather/current';
        $params = ['city' => 'London'];
        $apiResponse = ['temperature' => 15, 'condition' => 'cloudy', 'humidity' => 65];

        // Mock HTTP client
        Http::fake([
            '*' => Http::response($apiResponse, 200),
        ]);

        // First call should be a cache miss (API call required)
        $result1 = $this->service->fetch($endpoint, $params);
        $this->assertEquals($apiResponse, $result1);
        Http::assertSentCount(1);

        // Second call should be a cache hit (no API call)
        $result2 = $this->service->fetch($endpoint, $params);
        $this->assertEquals($apiResponse, $result2);
        Http::assertSentCount(1); // Still 1, no new request

        // Results should be identical
        $this->assertEquals($result1, $result2);
    }

    /**
     * Test TTL expiration behavior.
     */
    #[Test]
    public function testCacheTtlExpiration(): void
    {
        Config::set('apicache.ttl', 1); // 1 second TTL
        Config::set('apicache.timeout', 10);
        Config::set('apicache.external_apis', [
            'weather' => [
                'base_url' => 'https://api.weather.example.com',
                'api_key' => 'test-key',
            ],
        ]);

        $endpoint = 'weather/current';
        $apiResponse = ['temperature' => 10, 'condition' => 'rainy'];

        $callCount = 0;

        // Mock HTTP client with a callback to track calls
        Http::fake(function ($request) use (&$callCount, $apiResponse) {
            $callCount++;
            return Http::response($apiResponse, 200);
        });

        // First request
        $result1 = $this->service->fetch($endpoint, []);
        $this->assertEquals($apiResponse, $result1);
        $this->assertEquals(1, $callCount);

        // Wait for cache to expire
        sleep(2);

        // Second request (cache should be expired)
        $result2 = $this->service->fetch($endpoint, []);
        $this->assertEquals($apiResponse, $result2);
        $this->assertEquals(2, $callCount); // New request made
    }

    /**
     * Test cache statistics tracking.
     */
    #[Test]
    public function testCacheStatisticsTracking(): void
    {
        Config::set('apicache.ttl', 3600);
        Config::set('apicache.timeout', 10);
        Config::set('apicache.external_apis', [
            'weather' => [
                'base_url' => 'https://api.weather.example.com',
                'api_key' => 'test-key',
            ],
        ]);

        // Mock HTTP client
        Http::fake([
            '*' => Http::response(['temperature' => 20], 200),
        ]);

        $endpoint = 'weather/current';

        // Multiple requests with same parameters
        $this->service->fetch($endpoint, ['city' => 'London']);
        $this->service->fetch($endpoint, ['city' => 'London']);
        $this->service->fetch($endpoint, ['city' => 'London']);

        // Only 1 API request should have been made
        Http::assertSentCount(1);

        // Multiple requests with different parameters
        $this->service->fetch($endpoint, ['city' => 'Paris']);
        $this->service->fetch($endpoint, ['city' => 'Berlin']);

        // 3 total API requests (1 London, 1 Paris, 1 Berlin)
        Http::assertSentCount(3);
    }

    /**
     * Test failover behavior when cache backend is unavailable.
     */
    #[Test]
    public function testFailoverWhenCacheBackendUnavailable(): void
    {
        Config::set('apicache.ttl', 3600);
        Config::set('apicache.timeout', 10);
        Config::set('apicache.external_apis', [
            'weather' => [
                'base_url' => 'https://api.weather.example.com',
                'api_key' => 'test-key',
            ],
        ]);

        $endpoint = 'weather/current';
        $apiResponse = ['temperature' => 15, 'condition' => 'cloudy'];

        // Mock HTTP client
        Http::fake([
            '*' => Http::response($apiResponse, 200),
        ]);

        // Even if cache fails, API call should still work
        // This tests the service's resilience to cache backend failures
        $result = $this->service->fetch($endpoint, []);

        $this->assertEquals($apiResponse, $result);
        Http::assertSentCount(1);
    }

    /**
     * Test different cache keys for different parameter combinations.
     */
    #[Test]
    public function testDifferentCacheKeysForDifferentParams(): void
    {
        Config::set('apicache.ttl', 3600);
        Config::set('apicache.timeout', 10);
        Config::set('apicache.external_apis', [
            'weather' => [
                'base_url' => 'https://api.weather.example.com',
                'api_key' => 'test-key',
            ],
        ]);

        $endpoint = 'weather/current';
        $callCount = 0;

        Http::fake(function ($request) use (&$callCount) {
            $callCount++;
            if (str_contains($request->url(), 'city=London')) {
                return Http::response(['temperature' => 10, 'city' => 'London'], 200);
            } elseif (str_contains($request->url(), 'city=Paris')) {
                return Http::response(['temperature' => 20, 'city' => 'Paris'], 200);
            } else {
                return Http::response(['temperature' => 15, 'city' => 'Berlin'], 200);
            }
        });

        // Fetch data for different cities
        $london = $this->service->fetch($endpoint, ['city' => 'London']);
        $paris = $this->service->fetch($endpoint, ['city' => 'Paris']);
        $berlin = $this->service->fetch($endpoint, ['city' => 'Berlin']);

        // All three requests should have been made (different cache keys)
        $this->assertEquals(3, $callCount);

        // Results should be different
        $this->assertNotEquals($london, $paris);
        $this->assertNotEquals($paris, $berlin);

        // Subsequent requests with same params should use cache
        $london2 = $this->service->fetch($endpoint, ['city' => 'London']);
        $this->assertEquals($london, $london2);
        $this->assertEquals(3, $callCount); // Still 3 requests
    }

    /**
     * Test cache clearing and repopulation.
     */
    #[Test]
    public function testCacheClearingAndRepopulation(): void
    {
        Config::set('apicache.ttl', 3600);
        Config::set('apicache.timeout', 10);
        Config::set('apicache.external_apis', [
            'weather' => [
                'base_url' => 'https://api.weather.example.com',
                'api_key' => 'test-key',
            ],
        ]);

        $endpoint = 'weather/current';
        $firstResponse = ['temperature' => 10];
        $callCount = 0;

        Http::fake(function ($request) use (&$callCount, $firstResponse) {
            $callCount++;
            return Http::response($firstResponse, 200);
        });

        // First fetch
        $result1 = $this->service->fetch($endpoint, []);
        $this->assertEquals($firstResponse, $result1);
        $this->assertEquals(1, $callCount);

        // Clear cache
        Cache::flush();

        // Second fetch should trigger API call again
        $result2 = $this->service->fetch($endpoint, []);
        $this->assertEquals($firstResponse, $result2);
        $this->assertEquals(2, $callCount); // New request made
    }

    /**
     * Test concurrent requests with same endpoint produce single API call.
     */
    #[Test]
    public function testCachePreventsRaceConditions(): void
    {
        Config::set('apicache.ttl', 3600);
        Config::set('apicache.timeout', 10);
        Config::set('apicache.external_apis', [
            'weather' => [
                'base_url' => 'https://api.weather.example.com',
                'api_key' => 'test-key',
            ],
        ]);

        $endpoint = 'weather/current';
        $apiResponse = ['temperature' => 15];

        Http::fake([
            '*' => Http::response($apiResponse, 200),
        ]);

        // Simulate multiple rapid requests
        for ($i = 0; $i < 5; $i++) {
            $this->service->fetch($endpoint, ['city' => 'London']);
        }

        // Only 1 API call should have been made (cache handles the race)
        Http::assertSentCount(1);
    }
}
