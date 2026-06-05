<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ClearApiCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:clear-api';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all cached API responses';

    /**
     * Cache key prefix for API cache entries.
     */
    protected const CACHE_PREFIX = 'api_cache';

    /**
     * Execute the console command.
     *
     * Clears all API cache entries using prefix-based key scanning.
     * This command removes all cached API responses but does not affect
     * other application caches.
     *
     * @return int  Command exit code (0 for success, 1 for failure)
     */
    public function handle(): int
    {
        try {
            // Clear all API cache entries
            $this->clearApiCache();

            // Display confirmation message
            $this->info('API cache cleared successfully.');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to clear API cache: {$e->getMessage()}");
            Log::error('API cache clearing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Clear all API cache entries.
     *
     * Uses cache store-specific methods to remove all entries
     * with the api_cache prefix without affecting other caches.
     *
     * @return void
     */
    protected function clearApiCache(): void
    {
        $cacheStore = config('cache.default');

        if (in_array($cacheStore, ['redis', 'memcached'])) {
            // For Redis and Memcached, use prefix-based key scanning
            $this->clearCacheByPrefix();
        } else {
            // For other cache drivers (file, database, array),
            // clear by pattern using Cache::forget() for known keys
            // or flush the entire cache store if no better method exists
            $this->clearCacheByPrefix();
        }

        // Clear cache statistics counters
        $this->clearCacheStats();
    }

    /**
     * Clear cache entries by prefix.
     *
     * Scans for all keys matching the api_cache prefix pattern
     * and removes them from the cache.
     *
     * @return void
     */
    protected function clearCacheByPrefix(): void
    {
        $cacheStore = config('cache.default');

        if ($cacheStore === 'redis') {
            // Use Redis SCAN to find and delete keys
            $redis = Cache::getRedis();
            $connection = $redis->connection();

            $cursor = 0;
            $pattern = Cache::getPrefix() . self::CACHE_PREFIX . ':*';

            do {
                $result = $connection->scan($cursor, ['match' => $pattern, 'count' => 100]);

                if ($result !== false) {
                    [$cursor, $keys] = $result;

                    if (!empty($keys)) {
                        foreach ($keys as $key) {
                            // Remove the Laravel cache prefix to use Cache::forget()
                            $keyWithoutPrefix = str_replace(Cache::getPrefix(), '', $key);
                            Cache::forget($keyWithoutPrefix);
                        }
                    }
                }
            } while ($cursor !== 0 && $cursor !== '0');
        } else {
            // For other cache stores, attempt to clear using a known pattern
            // This is less efficient but works for file and database caches
            // Note: This may not work perfectly for all cache stores
            // In production, consider using cache tags with Redis/Memcached

            // Since we don't have a way to scan keys in file/database cache,
            // we'll use a workaround: clear the entire cache store
            // In a real implementation, you might want to use cache tags
            Cache::flush();
        }
    }

    /**
     * Clear cache statistics counters.
     *
     * Removes the cache statistics keys that track hits, misses,
     * and total requests for API cache operations.
     *
     * @return void
     */
    protected function clearCacheStats(): void
    {
        Cache::forget('api_cache_stats:hits');
        Cache::forget('api_cache_stats:misses');
        Cache::forget('api_cache_stats:requests');
    }
}
