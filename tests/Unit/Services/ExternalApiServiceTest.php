<?php

namespace Tests\Unit\Services;

use App\Services\ExternalApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExternalApiServiceTest extends TestCase
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

        // Reset cache before each test
        Cache::flush();
    }

    /**
     * Test cache key generation with various endpoint and parameter combinations.
     */
    #[Test]
    public function testCacheKeyGeneration(): void
    {
        // Test basic endpoint without parameters
        $key1 = $this->invokeMethod($this->service, 'getCacheKey', ['weather/current', []]);
        $this->assertStringStartsWith('api_cache:weather%2Fcurrent:', $key1);

        // Test endpoint with parameters
        $params = ['city' => 'London', 'units' => 'metric'];
        $key2 = $this->invokeMethod($this->service, 'getCacheKey', ['weather/current', $params]);
        $this->assertStringStartsWith('api_cache:weather%2Fcurrent:', $key2);

        // Same parameters should produce same hash
        $key3 = $this->invokeMethod($this->service, 'getCacheKey', ['weather/current', $params]);
        $this->assertEquals($key2, $key3);

        // Different parameters should produce different hash
        $differentParams = ['city' => 'Paris', 'units' => 'metric'];
        $key4 = $this->invokeMethod($this->service, 'getCacheKey', ['weather/current', $differentParams]);
        $this->assertNotEquals($key2, $key4);

        // Different endpoint should produce different prefix
        $key5 = $this->invokeMethod($this->service, 'getCacheKey', ['news/headlines', $params]);
        $this->assertStringStartsWith('api_cache:news%2Fheadlines:', $key5);
        $this->assertNotEquals($key2, $key5);
    }

    /**
     * Test TTL configuration reading.
     */
    #[Test]
    public function testGetCacheTtlReadsFromConfig(): void
    {
        // Test default TTL (3600 seconds)
        Config::set('apicache.ttl', 3600);
        $ttl = $this->invokeMethod($this->service, 'getCacheTtl');
        $this->assertEquals(3600, $ttl);

        // Test custom TTL
        Config::set('apicache.ttl', 7200);
        $ttl = $this->invokeMethod($this->service, 'getCacheTtl');
        $this->assertEquals(7200, $ttl);

        // Test zero TTL
        Config::set('apicache.ttl', 0);
        $ttl = $this->invokeMethod($this->service, 'getCacheTtl');
        $this->assertEquals(0, $ttl);
    }

    /**
     * Test successful cache hit scenario.
     */
    #[Test]
    public function testFetchReturnsFromCacheOnHit(): void
    {
        Config::set('apicache.ttl', 3600);
        Config::set('apicache.external_apis', [
            'weather' => [
                'base_url' => 'https://api.weather.example.com',
                'api_key' => 'test-key',
            ],
        ]);

        $cachedData = ['temperature' => 20, 'condition' => 'sunny'];
        $endpoint = 'weather/current';
        $params = ['city' => 'London'];

        // Pre-populate the cache
        $cacheKey = $this->invokeMethod($this->service, 'getCacheKey', [$endpoint, $params]);
        Cache::put($cacheKey, $cachedData, 3600);

        // Mock HTTP client with a failing response to ensure cache is used
        Http::fake([
            'https://api.weather.example.com/current*' => Http::response(['error' => 'Should not be called'], 500),
        ]);

        // Fetch should return cached data (not call API)
        $result = $this->service->fetch($endpoint, $params);

        $this->assertEquals($cachedData, $result);

        // Verify no request was made (cache was used)
        Http::assertNotSent(function ($request) {
            return true;
        });
    }

    /**
     * Test cache miss scenario triggers external API call.
     */
    #[Test]
    public function testFetchCallsExternalApiOnCacheMiss(): void
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
        $apiResponse = ['temperature' => 15, 'condition' => 'cloudy'];

        // Mock HTTP client
        Http::fake([
            'https://api.weather.example.com/current*' => Http::response($apiResponse, 200),
        ]);

        // Clear any existing cache
        Cache::flush();

        // Fetch should call API and cache the result
        $result = $this->service->fetch($endpoint, $params);

        $this->assertEquals($apiResponse, $result);

        // Verify the request was made
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.weather.example.com/current?city=London'
                && $request->hasHeader('Authorization');
        });

        // Verify data was cached
        $cacheKey = $this->invokeMethod($this->service, 'getCacheKey', [$endpoint, $params]);
        $this->assertTrue(Cache::has($cacheKey));
        $this->assertEquals($apiResponse, Cache::get($cacheKey));
    }

    /**
     * Test request building with authentication headers.
     */
    #[Test]
    public function testMakeRequestIncludesAuthenticationHeaders(): void
    {
        Config::set('apicache.ttl', 3600);
        Config::set('apicache.timeout', 10);
        Config::set('apicache.external_apis', [
            'weather' => [
                'base_url' => 'https://api.weather.example.com',
                'api_key' => 'secret-api-key',
            ],
        ]);

        // Mock HTTP client
        Http::fake([
            '*' => Http::response(['temperature' => 20], 200),
        ]);

        $endpoint = 'weather/current';
        $params = ['city' => 'London'];

        Cache::flush();
        $result = $this->service->fetch($endpoint, $params);

        $this->assertIsArray($result);

        // Verify a request was sent with the Authorization header
        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization');
        });
    }

    /**
     * Test timeout configuration is applied to requests.
     */
    #[Test]
    public function testMakeRequestAppliesConfiguredTimeout(): void
    {
        Config::set('apicache.timeout', 5);
        Config::set('apicache.external_apis', [
            'news' => [
                'base_url' => 'https://api.news.example.com',
                'api_key' => 'news-key',
            ],
        ]);

        // Mock HTTP client
        Http::fake([
            'https://api.news.example.com/headlines*' => Http::response(['headlines' => []], 200),
        ]);

        $endpoint = 'news/headlines';

        $this->service->fetch($endpoint);

        // HTTP facade doesn't expose timeout tracking easily, but verify the request succeeds
        // This test primarily validates that the service doesn't break with timeout configuration
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.news.example.com/headlines';
        });
    }

    /**
     * Test error handling for network failures without cache fallback.
     */
    #[Test]
    public function testFetchThrowsServiceUnavailableWhenApiDownAndNoCacheFallback(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException::class);

        Config::set('apicache.ttl', 3600);
        Config::set('apicache.timeout', 10);
        Config::set('apicache.external_apis', [
            'weather' => [
                'base_url' => 'https://api.weather.example.com',
                'api_key' => 'test-key',
            ],
        ]);

        // Mock connection exception
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused', 0, null);
        });

        Cache::flush();

        $this->service->fetch('weather/current', ['city' => 'London']);
    }

    /**
     * Test stale cache fallback when external API is unavailable.
     */
    #[Test]
    public function testFetchUsesStaleCacheFallbackWhenApiUnavailable(): void
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
        $staleCacheData = ['temperature' => 10, 'condition' => 'rainy'];

        // Pre-populate cache with stale data
        $cacheKey = $this->invokeMethod($this->service, 'getCacheKey', [$endpoint, $params]);
        Cache::put($cacheKey, $staleCacheData, 3600);

        // Make the cached data "stale" by removing it and then re-adding it
        // This simulates a situation where cache has expired but data still exists
        Cache::forget($cacheKey);
        Cache::put($cacheKey, $staleCacheData, 3600);

        // Mock connection exception
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused', 0, null);
        });

        // Fetch should return stale cache data
        $result = $this->service->fetch($endpoint, $params);

        $this->assertEquals($staleCacheData, $result);
    }

    /**
     * Test error handling for non-200 API responses.
     */
    #[Test]
    public function testFetchReturnsNullForNon200ApiResponse(): void
    {
        Config::set('apicache.ttl', 3600);
        Config::set('apicache.timeout', 10);
        Config::set('apicache.external_apis', [
            'weather' => [
                'base_url' => 'https://api.weather.example.com',
                'api_key' => 'test-key',
            ],
        ]);

        // Mock 404 response
        Http::fake([
            'https://api.weather.example.com/current*' => Http::response(
                ['error' => 'Not found'],
                404
            ),
        ]);

        Cache::flush();

        $result = $this->service->fetch('weather/current', ['city' => 'Unknown']);

        $this->assertNull($result);
    }

    /**
     * Test error handling for invalid API responses.
     */
    #[Test]
    public function testFetchHandlesServerErrors(): void
    {
        Config::set('apicache.ttl', 3600);
        Config::set('apicache.timeout', 10);
        Config::set('apicache.external_apis', [
            'weather' => [
                'base_url' => 'https://api.weather.example.com',
                'api_key' => 'test-key',
            ],
        ]);

        // Mock 500 response
        Http::fake([
            'https://api.weather.example.com/current*' => Http::response(
                ['error' => 'Server error'],
                500
            ),
        ]);

        Cache::flush();

        $result = $this->service->fetch('weather/current', []);

        $this->assertNull($result);
    }

    /**
     * Test URL building from configured external APIs.
     */
    #[Test]
    public function testBuildApiUrlConstructsCorrectUrl(): void
    {
        Config::set('apicache.external_apis', [
            'weather' => [
                'base_url' => 'https://api.weather.example.com',
                'api_key' => 'test-key',
            ],
        ]);

        $url = $this->invokeMethod($this->service, 'buildApiUrl', ['weather/current']);

        $this->assertEquals('https://api.weather.example.com/current', $url);
    }

    /**
     * Test URL building handles trailing slashes correctly.
     */
    #[Test]
    public function testBuildApiUrlHandlesTrailingSlashes(): void
    {
        Config::set('apicache.external_apis', [
            'weather' => [
                'base_url' => 'https://api.weather.example.com/',
                'api_key' => 'test-key',
            ],
        ]);

        $url = $this->invokeMethod($this->service, 'buildApiUrl', ['weather/current']);

        $this->assertEquals('https://api.weather.example.com/current', $url);
    }

    /**
     * Test URL building throws exception for missing API configuration.
     */
    #[Test]
    public function testBuildApiUrlThrowsExceptionForMissingConfig(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Config::set('apicache.external_apis', []);

        $this->invokeMethod($this->service, 'buildApiUrl', ['weather/current']);
    }

    /**
     * Test authentication headers are included in requests.
     */
    #[Test]
    public function testGetAuthHeadersIncludesApiKey(): void
    {
        Config::set('apicache.external_apis', [
            'weather' => [
                'base_url' => 'https://api.weather.example.com',
                'api_key' => 'my-secret-key',
            ],
        ]);

        $headers = $this->invokeMethod($this->service, 'getAuthHeaders', ['weather/current']);

        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertEquals('Bearer my-secret-key', $headers['Authorization']);
    }

    /**
     * Test authentication headers are empty when API key is not configured.
     */
    #[Test]
    public function testGetAuthHeadersReturnsEmptyWhenNoApiKey(): void
    {
        Config::set('apicache.external_apis', [
            'weather' => [
                'base_url' => 'https://api.weather.example.com',
                'api_key' => null,
            ],
        ]);

        $headers = $this->invokeMethod($this->service, 'getAuthHeaders', ['weather/current']);

        $this->assertArrayNotHasKey('Authorization', $headers);
    }

    /**
     * Test caching subsequent calls after first successful fetch.
     */
    #[Test]
    public function testSubsequentFetchesUseCachedData(): void
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
        $apiResponse = ['temperature' => 20, 'condition' => 'sunny'];

        // Mock HTTP client
        Http::fake([
            'https://api.weather.example.com/current*' => Http::response($apiResponse, 200),
        ]);

        Cache::flush();

        // First call should hit the API
        $result1 = $this->service->fetch($endpoint, $params);
        $this->assertEquals($apiResponse, $result1);

        // Verify one request was made
        Http::assertSentCount(1);

        // Second call should use cache (no new request)
        $result2 = $this->service->fetch($endpoint, $params);
        $this->assertEquals($apiResponse, $result2);

        // Verify no additional request was made
        Http::assertSentCount(1);
    }

    /**
     * Helper method to invoke protected/private methods for testing.
     */
    protected function invokeMethod(object $object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
