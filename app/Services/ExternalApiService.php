<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;

class ExternalApiService
{
    /**
     * The cache repository instance.
     */
    protected Repository $cache;

    /**
     * The configuration repository instance.
     */
    protected ConfigRepository $config;

    /**
     * The logger instance.
     */
    protected LoggerInterface $logger;

    /**
     * Cache key prefix for API cache entries.
     */
    protected const CACHE_PREFIX = 'api_cache';

    /**
     * Create a new external API service instance.
     */
    public function __construct(
        Repository $cache,
        ConfigRepository $config,
        LoggerInterface $logger
    ) {
        $this->cache = $cache;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Fetch data from an external API with caching.
     *
     * Implements a cache-first logic:
     * 1. Check if data exists in cache
     * 2. If cache hit, return cached data
     * 3. If cache miss, fetch from external API
     * 4. Store response in cache with TTL
     * 5. Return data
     *
     * @param  string  $endpoint  The external API endpoint (e.g., 'weather/current')
     * @param  array   $params    Query parameters to pass to the API
     * @return array|null         The API response data or null on error
     * @throws \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException
     */
    public function fetch(string $endpoint, array $params = []): ?array
    {
        $cacheKey = $this->getCacheKey($endpoint, $params);
        $ttl = $this->getCacheTtl();

        try {
            // Use cache remember to implement cache-first logic
            return $this->cache->remember($cacheKey, $ttl, function () use ($endpoint, $params, $cacheKey) {
                return $this->fetchFromApi($endpoint, $params, $cacheKey);
            });
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->logger->error('Failed to connect to external API', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            // Check if we have stale cache fallback
            if ($this->cache->has($cacheKey)) {
                $this->logger->info('Using stale cache fallback', [
                    'endpoint' => $endpoint,
                ]);
                return $this->cache->get($cacheKey);
            }

            // No stale cache available, throw service unavailable
            throw new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException(
                null,
                'External API is currently unavailable'
            );
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error fetching from external API', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Fetch data from the external API.
     *
     * @param  string  $endpoint
     * @param  array   $params
     * @param  string  $cacheKey
     * @return array|null
     */
    protected function fetchFromApi(string $endpoint, array $params, string $cacheKey): ?array
    {
        try {
            $response = $this->makeRequest($endpoint, $params);

            if (!$response->successful()) {
                $this->logger->warning('External API returned non-200 status', [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            return $response->json();
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Re-throw connection exceptions to allow stale cache fallback
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Error fetching from external API', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Generate a cache key for the given endpoint and parameters.
     *
     * The cache key pattern is: api_cache:{endpoint}:{param_hash}
     * Where param_hash is an MD5 hash of the JSON-encoded parameters.
     *
     * @param  string  $endpoint  The external API endpoint
     * @param  array   $params    The parameters
     * @return string             The cache key
     */
    protected function getCacheKey(string $endpoint, array $params = []): string
    {
        $encodedEndpoint = urlencode($endpoint);
        $paramHash = md5(json_encode($params));

        return sprintf('%s:%s:%s', self::CACHE_PREFIX, $encodedEndpoint, $paramHash);
    }

    /**
     * Get the cache TTL in seconds.
     *
     * Reads from the API_CACHE_TTL environment variable via config,
     * defaults to 3600 seconds (1 hour).
     *
     * @return int  The cache TTL in seconds
     */
    protected function getCacheTtl(): int
    {
        return $this->config->get('apicache.ttl', 3600);
    }

    /**
     * Make an HTTP request to the external API.
     *
     * Uses Laravel's HTTP client with:
     * - Configured timeout (default 10 seconds)
     * - Authentication via query parameters (APPID for OpenWeatherMap)
     * - Proper error handling
     *
     * @param  string  $endpoint  The external API endpoint
     * @param  array   $params    Query parameters
     * @return Response
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    protected function makeRequest(string $endpoint, array $params): Response
    {
        // Build the full URL
        $url = $this->buildApiUrl($endpoint);
        $timeout = $this->config->get('apicache.timeout', 10);

        // Add API key to query parameters (OpenWeatherMap uses APPID)
        $params = $this->addApiKeyToParams($endpoint, $params);

        try {
            // Make the request with timeout
            return Http::timeout($timeout)
                ->get($url, $params);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Re-throw connection exceptions to allow proper handling upstream
            throw $e;
        }
    }

    /**
     * Add API key to query parameters.
     *
     * OpenWeatherMap and most weather APIs expect the API key
     * as a query parameter (APPID, appid, etc.) rather than in headers.
     *
     * @param  string  $endpoint  The endpoint (e.g., 'weather/current')
     * @param  array   $params    Existing query parameters
     * @return array             Parameters with API key added
     */
    protected function addApiKeyToParams(string $endpoint, array $params): array
    {
        $parts = explode('/', $endpoint, 2);
        $apiName = $parts[0];

        $apiKey = $this->config->get("apicache.external_apis.{$apiName}.api_key");
        $keyParam = $this->config->get("apicache.external_apis.{$apiName}.key_param", 'APPID');

        if ($apiKey) {
            $params[$keyParam] = $apiKey;
        }

        return $params;
    }

    /**
     * Build the full URL for an external API endpoint.
     *
     * Looks up the base URL from the configured external APIs
     * and appends the endpoint path.
     *
     * @param  string  $endpoint  The endpoint (e.g., 'weather/weather')
     * @return string             The full URL
     */
    protected function buildApiUrl(string $endpoint): string
    {
        // Extract the API name from the endpoint (first part before /)
        $parts = explode('/', $endpoint, 2);
        $apiName = $parts[0];
        $endpointPath = $parts[1] ?? '';

        $baseUrl = $this->config->get("apicache.external_apis.{$apiName}.base_url");

        if (!$baseUrl) {
            throw new \InvalidArgumentException("No base URL configured for API: {$apiName}");
        }

        // Ensure no double slashes in the URL
        $baseUrl = rtrim($baseUrl, '/');

        // Only add slash if we have an endpoint path
        if ($endpointPath) {
            $endpointPath = '/' . ltrim($endpointPath, '/');
            return $baseUrl . $endpointPath;
        }

        return $baseUrl;
    }
}
