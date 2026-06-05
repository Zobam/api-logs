<?php

namespace Tests\Feature\Controllers;

use App\Services\ExternalApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

class CacheControllerTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        // Register the cache route for testing
        Route::prefix('api/cache')->group(function () {
            Route::get('/{resource}', [\App\Http\Controllers\CacheController::class, 'get'])
                ->where('resource', '.*')
                ->name('api.cache.get');
        });

        // Set test configuration
        config([
            'apicache.ttl' => 3600,
            'apicache.timeout' => 10,
            'apicache.external_apis.weather.base_url' => 'https://api.weather.com',
            'apicache.external_apis.weather.api_key' => 'test_weather_key',
            'apicache.external_apis.news.base_url' => 'https://api.news.com',
            'apicache.external_apis.news.api_key' => 'test_news_key',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_successful_retrieval_from_cache_with_cache_hit_header(): void
    {
        // Pre-populate cache with data
        $resource = 'weather/current';
        $queryParams = ['city' => 'London'];
        $cacheKey = sprintf(
            'api_cache:%s:%s',
            urlencode($resource),
            md5(json_encode($queryParams))
        );
        $cachedData = [
            'temperature' => 15,
            'conditions' => 'Partly cloudy',
            'humidity' => 65,
        ];

        Cache::put($cacheKey, $cachedData, 3600);

        // Make request
        $response = $this->getJson('/api/cache/weather/current?city=London');

        // Assert response
        $response->assertStatus(200);
        $response->assertHeader('X-Cache-Hit', 'true');
        $response->assertHeader('X-Cache-TTL', '3600');
        $response->assertHeader('Content-Type', 'application/json');

        // Assert response structure
        $response->assertJsonStructure([
            'data',
            'cached',
            'expires_at',
        ]);

        // Assert response data
        $response->assertJson([
            'data' => $cachedData,
            'cached' => true,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_cache_miss_triggers_external_api_call_with_cache_miss_header(): void
    {
        // Mock HTTP client to simulate external API
        Http::fake([
            'https://api.weather.com/current*' => Http::response([
                'temperature' => 20,
                'conditions' => 'Sunny',
                'humidity' => 50,
            ], 200),
        ]);

        // Ensure cache is empty
        Cache::flush();

        // Make request
        $response = $this->getJson('/api/cache/weather/current?city=Paris');

        // Assert response
        $response->assertStatus(200);
        $response->assertHeader('X-Cache-Hit', 'false');
        $response->assertHeader('X-Cache-TTL', '3600');
        $response->assertHeader('Content-Type', 'application/json');

        // Assert response structure
        $response->assertJsonStructure([
            'data',
            'cached',
            'expires_at',
        ]);

        // Assert response data
        $response->assertJson([
            'data' => [
                'temperature' => 20,
                'conditions' => 'Sunny',
                'humidity' => 50,
            ],
            'cached' => false,
        ]);

        // Verify data is now cached
        $cacheKey = sprintf(
            'api_cache:%s:%s',
            urlencode('weather/current'),
            md5(json_encode(['city' => 'Paris']))
        );
        $this->assertTrue(Cache::has($cacheKey));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_error_response_when_external_api_is_unavailable(): void
    {
        // Mock HTTP client to simulate connection failure
        Http::fake([
            'https://api.weather.com/*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection failed');
            },
        ]);

        // Ensure cache is empty (no stale cache fallback)
        Cache::flush();

        // Make request
        $response = $this->getJson('/api/cache/weather/current?city=Berlin');

        // Assert 503 Service Unavailable response
        $response->assertStatus(503);
        $response->assertHeader('Content-Type', 'application/json');

        // Assert error response structure
        $response->assertJsonStructure([
            'error',
            'message',
        ]);

        // Assert error message
        $response->assertJson([
            'error' => 'External API is currently unavailable',
            'message' => 'The requested data could not be retrieved. Please try again later.',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_json_response_format_and_structure(): void
    {
        // Mock HTTP client
        Http::fake([
            'https://api.news.com/headlines*' => Http::response([
                'articles' => [
                    ['title' => 'Breaking News', 'author' => 'John Doe'],
                ],
            ], 200),
        ]);

        Cache::flush();

        // Make request
        $response = $this->getJson('/api/cache/news/headlines');

        // Assert JSON response
        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'articles' => [
                    ['title' => 'Breaking News', 'author' => 'John Doe'],
                ],
            ],
            'cached' => false,
        ]);

        // Assert complete structure
        $response->assertJsonStructure([
            'data' => [
                'articles' => [
                    '*' => ['title', 'author'],
                ],
            ],
            'cached',
            'expires_at',
        ]);

        // Verify expires_at is a valid ISO8601 string
        $data = $response->json();
        $this->assertNotNull($data['expires_at']);
        $this->assertIsString($data['expires_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $data['expires_at']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_response_headers_are_correctly_set(): void
    {
        // Mock HTTP client
        Http::fake([
            'https://api.weather.com/current*' => Http::response([
                'temperature' => 18,
            ], 200),
        ]);

        Cache::flush();

        // Make request (cache miss)
        $response = $this->getJson('/api/cache/weather/current?city=Madrid');

        // Verify all required headers
        $response->assertHeader('X-Cache-Hit', 'false');
        $response->assertHeader('X-Cache-TTL', '3600');
        $response->assertHeader('Content-Type', 'application/json');

        // Now make the same request again (cache hit)
        $response2 = $this->getJson('/api/cache/weather/current?city=Madrid');

        // Verify headers for cache hit
        $response2->assertHeader('X-Cache-Hit', 'true');
        $response2->assertHeader('X-Cache-TTL', '3600');
        $response2->assertHeader('Content-Type', 'application/json');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_cache_hit_with_different_query_parameters(): void
    {
        // Pre-populate cache for specific query params
        $resource = 'weather/current';
        $queryParams1 = ['city' => 'London', 'units' => 'metric'];
        $queryParams2 = ['city' => 'London', 'units' => 'imperial'];

        $cacheKey1 = sprintf(
            'api_cache:%s:%s',
            urlencode($resource),
            md5(json_encode($queryParams1))
        );

        Cache::put($cacheKey1, ['temperature' => 15], 3600);

        // Request with cached params should hit cache
        $response1 = $this->getJson('/api/cache/weather/current?city=London&units=metric');
        $response1->assertHeader('X-Cache-Hit', 'true');

        // Mock for different params
        Http::fake([
            'https://api.weather.com/current*' => Http::response(['temperature' => 59], 200),
        ]);

        // Request with different params should miss cache
        $response2 = $this->getJson('/api/cache/weather/current?city=London&units=imperial');
        $response2->assertHeader('X-Cache-Hit', 'false');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_handles_invalid_resource_path(): void
    {
        // Mock HTTP client to return 404 for invalid endpoint
        Http::fake([
            '*' => Http::response(['error' => 'Not found'], 404),
        ]);

        Cache::flush();

        // Make request with resource that doesn't exist
        $response = $this->getJson('/api/cache/invalid/endpoint');

        // Assert 404 response from controller
        $response->assertStatus(404);
        $response->assertJson([
            'error' => 'Resource not found',
            'message' => 'The requested resource does not exist.',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_handles_external_api_returning_error_status(): void
    {
        // Mock HTTP client to return 404
        Http::fake([
            'https://api.weather.com/invalid*' => Http::response(['error' => 'Not found'], 404),
        ]);

        Cache::flush();

        // Make request
        $response = $this->getJson('/api/cache/weather/invalid');

        // Assert 404 response
        $response->assertStatus(404);
        $response->assertJson([
            'error' => 'Resource not found',
            'message' => 'The requested resource does not exist.',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_stale_cache_fallback_when_api_unavailable(): void
    {
        // Pre-populate cache with stale data
        $resource = 'weather/current';
        $queryParams = ['city' => 'Tokyo'];
        $cacheKey = sprintf(
            'api_cache:%s:%s',
            urlencode($resource),
            md5(json_encode($queryParams))
        );
        $staleData = ['temperature' => 25, 'conditions' => 'Clear'];

        Cache::put($cacheKey, $staleData, 3600);

        // Mock connection failure
        Http::fake([
            'https://api.weather.com/*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timeout');
            },
        ]);

        // Make request - should return stale cache
        $response = $this->getJson('/api/cache/weather/current?city=Tokyo');

        // Should succeed with stale data
        $response->assertStatus(200);
        $response->assertJson([
            'data' => $staleData,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_nested_resource_paths_are_handled_correctly(): void
    {
        // Mock HTTP client for nested path
        Http::fake([
            'https://api.news.com/v1/articles/trending*' => Http::response([
                'results' => ['article1', 'article2'],
            ], 200),
        ]);

        Cache::flush();

        // Make request with nested path
        $response = $this->getJson('/api/cache/news/v1/articles/trending');

        // Assert successful response
        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'results' => ['article1', 'article2'],
            ],
            'cached' => false,
        ]);
    }
}
