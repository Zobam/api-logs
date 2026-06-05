<?php

namespace App\Http\Controllers;

use App\Services\ExternalApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CacheController extends Controller
{
    /**
     * Create a new cache controller instance.
     *
     * @param  ExternalApiService  $apiService  The external API service
     */
    public function __construct(protected ExternalApiService $apiService) {}

    /**
     * Retrieve cached data for a specific resource.
     *
     * Implements the following logic:
     * 1. Check if data exists in cache
     * 2. If cache hit, return cached data with X-Cache-Hit: true
     * 3. If cache miss, fetch from external API with X-Cache-Hit: false
     * 4. Return JSON response with cache headers
     *
     * @param  Request  $request   The HTTP request
     * @param  string   $resource  The resource path (e.g., 'weather/current')
     * @return JsonResponse
     */
    public function get(Request $request, string $resource): JsonResponse
    {
        try {
            // Validate that a resource is provided
            if (empty($resource)) {
                return $this->jsonErrorResponse(
                    'Invalid resource',
                    'The requested resource does not exist.',
                    404
                );
            }

            // Generate cache key to check if data exists
            $cacheKey = $this->generateCacheKey($resource, $request->query());
            $cacheHit = Cache::has($cacheKey);

            // Fetch data from service (cache-first via ExternalApiService)
            $data = $this->apiService->fetch($resource, $request->query());

            if ($data === null) {
                return $this->jsonErrorResponse(
                    'Resource not found',
                    'The requested resource does not exist.',
                    404
                );
            }

            // Get TTL from config for the response header
            $ttl = config('apicache.ttl', 3600);

            return response()->json(
                [
                    'data' => $data,
                    'cached' => $cacheHit,
                    'expires_at' => now()->addSeconds($ttl)->toIso8601String(),
                ],
                200,
                [],
                JSON_UNESCAPED_SLASHES
            )
                ->header('X-Cache-Hit', $cacheHit ? 'true' : 'false')
                ->header('X-Cache-TTL', (string)$ttl)
                ->header('Content-Type', 'application/json');
        } catch (HttpException $e) {
            // Handle HTTP exceptions (e.g., ServiceUnavailableHttpException)
            if ($e->getStatusCode() === 503) {
                return $this->jsonErrorResponse(
                    'External API is currently unavailable',
                    'The requested data could not be retrieved. Please try again later.',
                    503
                );
            }

            throw $e;
        } catch (\Exception $e) {
            // Log unexpected errors
            Log::error('Error in CacheController::get', [
                'resource' => $resource,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return $this->jsonErrorResponse(
                'Internal server error',
                'An unexpected error occurred while processing your request.',
                500
            );
        }
    }

    /**
     * Generate a cache key for the given resource and query parameters.
     *
     * @param  string  $resource  The resource path
     * @param  array   $params    Query parameters
     * @return string
     */
    protected function generateCacheKey(string $resource, array $params = []): string
    {
        $encodedResource = urlencode($resource);
        $paramHash = md5(json_encode($params));

        return sprintf('api_cache:%s:%s', $encodedResource, $paramHash);
    }

    /**
     * Return a JSON error response.
     *
     * @param  string  $error     The error message
     * @param  string  $message   The user-friendly message
     * @param  int     $status    The HTTP status code
     * @return JsonResponse
     */
    protected function jsonErrorResponse(string $error, string $message, int $status): JsonResponse
    {
        return response()->json(
            [
                'error' => $error,
                'message' => $message,
            ],
            $status,
            [],
            JSON_UNESCAPED_SLASHES
        )->header('Content-Type', 'application/json');
    }
}
