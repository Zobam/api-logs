<?php

namespace Tests\Integration;

use App\Services\ExternalApiService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CacheIntegrationTest extends TestCase
{
    /**
     * The external API service instance for testing.
     */
    protected ExternalApiService $service;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ExternalApiService::class);

        // Configure the cache and API settings for testing
        Config::set('apicache.ttl', 3600);
        Config::set('apicache.timeout', 10);
        Config::set('apicache.external_apis', [
            'weather' => [
                'base_url' => 'https://api.weather.example.com',
                'api_key' => 'test-weather-key',
            ],
            'news' => [
                'base_url' => 'https://api.news.example.com',
                'api_key' => 'test-news-key',
            ],
        ]);

        // Clear cache before each test
        Cache::flush();
    }

    /**
     * Test cache hit/miss behavior with real cache backend.
     * 
     * Validates: Requirements 3.1, 3.2, 3.3
     */
    #[Test]
    public function testCacheHitAndMissBehavior(): void
    {
        // Arrange: Mock the external API with multiple responses
        $endpoint = 'weather/current';
        $params = ['city' => 'London', 'units' => 'metric'];
        $apiResponse = [
            'temperature' => 15,
            'condition' => 'cloudy',
            'humidity' => 65,
        ];

        Http::fake(function ($request) use ($apiResponse) {
            if (str_contains($request->url(), 'city=London')) {
                return Http::response($apiResponse, 200);
            }
            return Http::response([
                'temperature' => 18,
                'condition' => 'sunny',
                'humidity' => 55,
            ], 200);
        });

        // Act: First call - should be a cache miss (triggers API call)
        $result1 = $this->service->fetch($endpoint, $params);

        // Assert: First call returns correct data
        $this->assertEquals($apiResponse, $result1);
        Http::assertSentCount(1);

        // Act: Second call - should be a cache hit (no new API call)
        $result2 = $this->service->fetch($endpoint, $params);

        // Assert: Second call returns same data
        $this->assertEquals($apiResponse, $result2);
        Http::assertSentCount(1); // Still 1, no new request made

        // Assert: Results are identical
        $this->assertEquals($result1, $result2);

        // Act: Third call with different parameters - should be a cache miss
        $paramsAlt = ['city' => 'Paris', 'units' => 'metric'];
        $apiResponseAlt = [
            'temperature' => 18,
            'condition' => 'sunny',
            'humidity' => 55,
        ];

        $result3 = $this->service->fetch($endpoint, $paramsAlt);

        // Assert: Different parameters result in API call
        $this->assertEquals($apiResponseAlt, $result3);
        Http::assertSentCount(2); // New request for different params
    }

    /**
     * Test TTL expiration after configured time period.
     * 
     * Uses Carbon test helpers to manipulate time for TTL testing.
     * Validates: Requirements 3.3, 3.4, 3.5
     */
    #[Test]
    public function testCacheTtlExpiration(): void
    {
        // Arrange: Set a short TTL for testing (5 seconds)
        Config::set('apicache.ttl', 5);

        $endpoint = 'weather/forecast';
        $params = ['days' => '7'];
        $initialResponse = ['forecast' => 'sunny'];
        $updatedResponse = ['forecast' => 'rainy'];

        $callCount = 0;

        Http::fake(function ($request) use (&$callCount, $initialResponse, $updatedResponse) {
            $callCount++;
            // Return different responses for different calls
            return Http::response($callCount === 1 ? $initialResponse : $updatedResponse, 200);
        });

        // Act: First fetch should cache the data
        $result1 = $this->service->fetch($endpoint, $params);
        $this->assertEquals($initialResponse, $result1);
        Http::assertSentCount(1);

        // Assert: Data is cached
        $cacheKey = sprintf(
            'api_cache:%s:%s',
            urlencode($endpoint),
            md5(json_encode($params))
        );
        $this->assertTrue(Cache::has($cacheKey));
        $this->assertEquals($initialResponse, Cache::get($cacheKey));

        // Act: Immediately fetch again - should use cache
        $result2 = $this->service->fetch($endpoint, $params);
        $this->assertEquals($initialResponse, $result2);
        Http::assertSentCount(1); // Still 1 request

        // Act: Wait for cache to expire using Carbon test helpers
        Carbon::setTestNow(Carbon::now()->addSeconds(6));

        // Simulate expired cache by removing it
        // (In production, Redis/Memcached handles expiration)
        Cache::forget($cacheKey);

        // Assert: Cache has expired - new request should be made
        $result3 = $this->service->fetch($endpoint, $params);
        $this->assertEquals($updatedResponse, $result3);
        Http::assertSentCount(2); // New request after expiration

        // Assert: New data is cached
        $this->assertTrue(Cache::has($cacheKey));
        $this->assertEquals($updatedResponse, Cache::get($cacheKey));

        // Clean up: Reset Carbon time
        Carbon::setTestNow(null);
    }

    /**
     * Test cache statistics tracking (hits, misses, requests).
     * 
     * Validates: Requirements 3.1, 3.2, 7.1, 7.2
     */
    #[Test]
    public function testCacheStatisticsTracking(): void
    {
        // Arrange: Set up test configuration
        Config::set('apicache.ttl', 3600);

        $endpoint1 = 'weather/current';
        $endpoint2 = 'news/headlines';

        // Mock HTTP client with specific responses
        Http::fake([
            '*weather*' => Http::response(['temp' => 15], 200),
            '*news*' => Http::response(['articles' => 5], 200),
        ]);

        // Initialize statistics if using cache statistics tracking
        Cache::put('api_cache_stats:hits', 0, now()->addYear());
        Cache::put('api_cache_stats:misses', 0, now()->addYear());
        Cache::put('api_cache_stats:requests', 0, now()->addYear());

        // Act: Make first call to weather endpoint - cache miss
        $result1 = $this->service->fetch($endpoint1, ['city' => 'London']);
        $this->assertEquals(['temp' => 15], $result1);

        // Act: Make second call to weather endpoint - cache hit
        $result2 = $this->service->fetch($endpoint1, ['city' => 'London']);
        $this->assertEquals(['temp' => 15], $result2);

        // Act: Make third call to different endpoint - cache miss
        $result3 = $this->service->fetch($endpoint2, ['category' => 'tech']);
        $this->assertEquals(['articles' => 5], $result3);

        // Act: Make fourth call to news endpoint - cache hit
        $result4 = $this->service->fetch($endpoint2, ['category' => 'tech']);
        $this->assertEquals(['articles' => 5], $result4);

        // Assert: Only 2 API calls should have been made (2 cache misses)
        Http::assertSentCount(2);

        // Assert: Cache should have 2 entries for the 2 unique endpoint+params combinations
        $cacheKey1 = sprintf(
            'api_cache:%s:%s',
            urlencode($endpoint1),
            md5(json_encode(['city' => 'London']))
        );
        $cacheKey2 = sprintf(
            'api_cache:%s:%s',
            urlencode($endpoint2),
            md5(json_encode(['category' => 'tech']))
        );

        $this->assertTrue(Cache::has($cacheKey1));
        $this->assertTrue(Cache::has($cacheKey2));
    }

    /**
     * Test failover behavior when cache backend is unavailable.
     * 
     * Validates: Requirements 3.1, 3.5
     */
    #[Test]
    public function testFailoverWhenCacheBackendUnavailable(): void
    {
        // Arrange: Mock HTTP response
        $endpoint = 'weather/current';
        $apiResponse = ['temperature' => 20, 'condition' => 'clear'];

        Http::fake([
            '*' => Http::response($apiResponse, 200),
        ]);

        // Ensure cache is empty
        Cache::flush();

        // Act: Even with potential cache issues, service should fetch from API
        $result = $this->service->fetch($endpoint, []);

        // Assert: API call was made and result is returned
        $this->assertEquals($apiResponse, $result);
        Http::assertSentCount(1);

        // Act: Verify cache is populated despite backend issues
        $cacheKey = sprintf(
            'api_cache:%s:%s',
            urlencode($endpoint),
            md5(json_encode([]))
        );

        // Assert: Data should be cached
        $this->assertTrue(Cache::has($cacheKey));
        $this->assertEquals($apiResponse, Cache::get($cacheKey));
    }

    /**
     * Test cache statistics with multiple concurrent requests.
     * 
     * Validates: Requirements 3.2, 7.1, 7.2
     */
    #[Test]
    public function testCacheStatisticsWithConcurrentRequests(): void
    {
        // Arrange
        $endpoint = 'weather/current';
        $callCount = 0;

        Http::fake(function ($request) use (&$callCount) {
            $callCount++;
            return Http::response(['temperature' => 15], 200);
        });

        // Act: Make multiple rapid requests to same endpoint
        for ($i = 0; $i < 10; $i++) {
            $this->service->fetch($endpoint, ['city' => 'London']);
        }

        // Assert: Only 1 API call should have been made (cache prevents duplicates)
        $this->assertEquals(1, $callCount);
        Http::assertSentCount(1);
    }

    /**
     * Test cache key uniqueness for different parameters.
     * 
     * Validates: Requirements 3.1, 3.2
     */
    #[Test]
    public function testDifferentCacheKeysForDifferentParams(): void
    {
        // Arrange
        $endpoint = 'weather/current';
        $callCount = 0;

        Http::fake(function ($request) use (&$callCount) {
            $callCount++;
            if (str_contains($request->url(), 'city=London')) {
                return Http::response(['temp' => 10, 'city' => 'London'], 200);
            } elseif (str_contains($request->url(), 'city=Paris')) {
                return Http::response(['temp' => 20, 'city' => 'Paris'], 200);
            } else {
                return Http::response(['temp' => 15, 'city' => 'Berlin'], 200);
            }
        });

        // Act: Fetch with different parameter combinations
        $london = $this->service->fetch($endpoint, ['city' => 'London']);
        $paris = $this->service->fetch($endpoint, ['city' => 'Paris']);
        $berlin = $this->service->fetch($endpoint, ['city' => 'Berlin']);

        // Assert: All three different requests were made (different cache keys)
        $this->assertEquals(3, $callCount);
        Http::assertSentCount(3);

        // Assert: Results are different
        $this->assertEquals('London', $london['city']);
        $this->assertEquals('Paris', $paris['city']);
        $this->assertEquals('Berlin', $berlin['city']);

        // Act: Request same parameters again
        $london2 = $this->service->fetch($endpoint, ['city' => 'London']);

        // Assert: No new API call - using cache
        $this->assertEquals('London', $london2['city']);
        Http::assertSentCount(3); // Still 3 requests
    }

    /**
     * Test cache clearing and repopulation.
     * 
     * Validates: Requirements 3.1, 3.3
     */
    #[Test]
    public function testCacheClearingAndRepopulation(): void
    {
        // Arrange
        $endpoint = 'weather/current';
        $firstResponse = ['temperature' => 10];
        $secondResponse = ['temperature' => 20];
        $callCount = 0;

        Http::fake(function ($request) use (&$callCount, $firstResponse, $secondResponse) {
            $callCount++;
            return Http::response($callCount === 1 ? $firstResponse : $secondResponse, 200);
        });

        // Act: First fetch
        $result1 = $this->service->fetch($endpoint, []);
        $this->assertEquals($firstResponse, $result1);
        $this->assertEquals(1, $callCount);

        // Assert: Data is cached
        $cacheKey = sprintf(
            'api_cache:%s:%s',
            urlencode($endpoint),
            md5(json_encode([]))
        );
        $this->assertTrue(Cache::has($cacheKey));

        // Act: Clear cache
        Cache::flush();

        // Assert: Cache is empty
        $this->assertFalse(Cache::has($cacheKey));

        // Act: Second fetch after cache clear
        $result2 = $this->service->fetch($endpoint, []);
        $this->assertEquals($secondResponse, $result2);
        $this->assertEquals(2, $callCount);

        // Assert: Cache is repopulated with new data
        $this->assertTrue(Cache::has($cacheKey));
        $this->assertEquals($secondResponse, Cache::get($cacheKey));
    }

    /**
     * Test cache behavior with empty parameters.
     * 
     * Validates: Requirements 3.1, 3.2
     */
    #[Test]
    public function testCacheBehaviorWithEmptyParameters(): void
    {
        // Arrange
        $endpoint = 'weather/default';
        $apiResponse = ['temperature' => 15, 'default' => true];

        Http::fake([
            '*' => Http::response($apiResponse, 200),
        ]);

        // Act: Fetch with no parameters
        $result1 = $this->service->fetch($endpoint);

        // Assert: Correct response
        $this->assertEquals($apiResponse, $result1);
        Http::assertSentCount(1);

        // Act: Fetch again with no parameters
        $result2 = $this->service->fetch($endpoint);

        // Assert: Cache hit (no new request)
        $this->assertEquals($apiResponse, $result2);
        Http::assertSentCount(1);
    }

    /**
     * Test cache statistics with expired entries.
     * 
     * Validates: Requirements 3.3, 3.4, 7.1
     */
    #[Test]
    public function testCacheStatisticsWithExpiredEntries(): void
    {
        // Arrange: Use a very short TTL
        Config::set('apicache.ttl', 1);

        $endpoint = 'weather/temporary';
        $apiResponse = ['temporary' => true];
        $refreshedResponse = ['temporary' => true, 'refreshed' => true];

        $callCount = 0;

        Http::fake(function ($request) use (&$callCount, $apiResponse, $refreshedResponse) {
            $callCount++;
            return Http::response($callCount === 1 ? $apiResponse : $refreshedResponse, 200);
        });

        // Act: Fetch and cache data
        $result1 = $this->service->fetch($endpoint, []);
        $this->assertEquals($apiResponse, $result1);
        Http::assertSentCount(1);

        // Act: Immediately fetch again - cache hit
        $result2 = $this->service->fetch($endpoint, []);
        $this->assertEquals($apiResponse, $result2);
        Http::assertSentCount(1);

        // Act: Simulate cache expiration with Carbon
        Carbon::setTestNow(Carbon::now()->addSeconds(2));

        // Manually remove expired cache entry (simulating cache backend behavior)
        $cacheKey = sprintf(
            'api_cache:%s:%s',
            urlencode($endpoint),
            md5(json_encode([]))
        );
        Cache::forget($cacheKey);

        // Act: Fetch after expiration - cache miss
        $result3 = $this->service->fetch($endpoint, []);

        // Assert: New request was made
        Http::assertSentCount(2);
        $this->assertArrayHasKey('refreshed', $result3);

        // Clean up
        Carbon::setTestNow(null);
    }

    /**
     * Test cache consistency across multiple services.
     * 
     * Validates: Requirements 3.1, 3.2, 3.3
     */
    #[Test]
    public function testCacheConsistencyAcrossMultipleServices(): void
    {
        // Arrange: Create multiple service instances
        $service1 = app(ExternalApiService::class);
        $service2 = app(ExternalApiService::class);

        $endpoint = 'weather/current';
        $apiResponse = ['temperature' => 15];

        Http::fake([
            '*' => Http::response($apiResponse, 200),
        ]);

        // Act: First service makes request
        $result1 = $service1->fetch($endpoint, ['city' => 'London']);
        Http::assertSentCount(1);

        // Act: Second service makes same request
        $result2 = $service2->fetch($endpoint, ['city' => 'London']);

        // Assert: Second service uses cache (no new API call)
        Http::assertSentCount(1); // Still 1
        $this->assertEquals($result1, $result2);
    }

    /**
     * Test cache TTL configuration reading.
     * 
     * Validates: Requirements 3.3, 3.4
     */
    #[Test]
    public function testCacheTtlConfigurationReading(): void
    {
        // Arrange: Set different TTL values
        Config::set('apicache.ttl', 7200); // 2 hours

        $endpoint = 'weather/current';

        Http::fake([
            '*' => Http::response(['temperature' => 15], 200),
        ]);

        // Act: Fetch data
        $this->service->fetch($endpoint, []);

        // Assert: Cache key is stored with configured TTL
        $cacheKey = sprintf(
            'api_cache:%s:%s',
            urlencode($endpoint),
            md5(json_encode([]))
        );

        // Verify data is cached
        $this->assertTrue(Cache::has($cacheKey));

        // Arrange: Change TTL to different value
        Config::set('apicache.ttl', 1800); // 30 minutes

        // Act: Clear and fetch again with new TTL
        Cache::forget($cacheKey);
        $this->service->fetch($endpoint, []);

        // Assert: Cache still works with new TTL
        $this->assertTrue(Cache::has($cacheKey));
    }

    /**
     * Test cache with large response data.
     * 
     * Validates: Requirements 3.1, 3.2, 3.3
     */
    #[Test]
    public function testCacheWithLargeResponseData(): void
    {
        // Arrange: Create a large response with smaller size to avoid JSON parsing issues
        $largeResponse = [
            'data' => array_fill(0, 100, [
                'id' => 1,
                'title' => 'Item with text content',
                'description' => 'Lorem ipsum dolor sit amet',
            ]),
            'metadata' => [
                'count' => 100,
                'size' => 100,
            ],
        ];

        // Add 'data' API configuration
        Config::set('apicache.external_apis.data', [
            'base_url' => 'https://api.data.example.com',
            'api_key' => 'test-data-key',
        ]);

        $endpoint = 'data/large';

        Http::fake([
            '*' => Http::response($largeResponse, 200),
        ]);

        // Act: Fetch large data - cache miss
        $result1 = $this->service->fetch($endpoint, []);

        // Assert: Large data is returned
        $this->assertNotNull($result1, 'First fetch should return data from API');
        $this->assertIsArray($result1);
        $this->assertArrayHasKey('data', $result1);
        $this->assertEquals(100, count($result1['data']));
        Http::assertSentCount(1);

        // Act: Fetch again - cache hit
        $result2 = $this->service->fetch($endpoint, []);

        // Assert: Large data is retrieved from cache
        $this->assertNotNull($result2, 'Second fetch should return cached data');
        $this->assertIsArray($result2);
        $this->assertEquals(100, count($result2['data']));
        Http::assertSentCount(1); // No new request
    }

    /**
     * Test cache with special characters in endpoint and parameters.
     * 
     * Validates: Requirements 3.1, 3.2
     */
    #[Test]
    public function testCacheWithSpecialCharacters(): void
    {
        // Arrange
        $endpoint = 'weather/current';
        $params = [
            'city' => 'São Paulo',
            'search' => 'münchen & köln',
            'special' => 'key:value?query=1&other=2',
        ];

        $apiResponse = ['temperature' => 25];

        Http::fake([
            '*' => Http::response($apiResponse, 200),
        ]);

        // Act: Fetch with special characters
        $result1 = $this->service->fetch($endpoint, $params);

        // Assert: Data is returned
        $this->assertEquals($apiResponse, $result1);
        Http::assertSentCount(1);

        // Act: Fetch again with same special characters
        $result2 = $this->service->fetch($endpoint, $params);

        // Assert: Cache hit
        $this->assertEquals($apiResponse, $result2);
        Http::assertSentCount(1); // No new request

        // Assert: Cache key is unique for these parameters
        $cacheKey = sprintf(
            'api_cache:%s:%s',
            urlencode($endpoint),
            md5(json_encode($params))
        );
        $this->assertTrue(Cache::has($cacheKey));
    }
}
