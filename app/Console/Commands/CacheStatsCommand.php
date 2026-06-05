<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Console\Helper\Table;

class CacheStatsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:stats';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display API cache statistics';

    /**
     * Cache key prefix for API cache entries.
     */
    protected const CACHE_PREFIX = 'api_cache';

    /**
     * Execute the console command.
     *
     * Retrieves cache statistics (hits, misses, total requests) and displays them
     * in a formatted table. Calculates and displays hit rate percentage.
     *
     * @return int  Command exit code (0 for success, 1 for failure)
     */
    public function handle(): int
    {
        try {
            // Retrieve cache statistics
            $stats = $this->getCacheStatistics();

            // Display statistics in a formatted table
            $this->displayStatisticsTable($stats);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to retrieve cache statistics: {$e->getMessage()}");
            Log::error('Cache statistics retrieval failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Retrieve cache statistics from the cache backend.
     *
     * Fetches hit count, miss count, and total requests from cache statistics keys.
     * Handles missing statistics gracefully by returning N/A or default values.
     *
     * @return array  Associative array containing hits, misses, requests, and cached_entries
     */
    protected function getCacheStatistics(): array
    {
        $hits = $this->getCacheStatistic('api_cache_stats:hits');
        $misses = $this->getCacheStatistic('api_cache_stats:misses');
        $requests = $hits + $misses;
        $cachedEntries = $this->getCachedEntryCount();

        return [
            'hits' => $hits,
            'misses' => $misses,
            'requests' => $requests,
            'cached_entries' => $cachedEntries,
            'hit_rate' => $requests > 0 ? round(($hits / $requests) * 100, 2) : 0,
        ];
    }

    /**
     * Get a specific cache statistic value.
     *
     * Retrieves a statistic from the cache backend. If the statistic does not exist,
     * returns 0. Handles graceful failures when cache backend is unavailable.
     *
     * @param string $key  The cache statistic key (e.g., 'api_cache_stats:hits')
     * @return int|string  The statistic value or 'N/A' if unavailable
     */
    protected function getCacheStatistic(string $key): int|string
    {
        try {
            $value = Cache::get($key, 0);
            return is_numeric($value) ? (int) $value : 0;
        } catch (\Exception $e) {
            Log::warning("Failed to retrieve cache statistic: {$key}", [
                'error' => $e->getMessage(),
            ]);
            return 'N/A';
        }
    }

    /**
     * Get the count of currently cached API entries.
     *
     * Scans the cache backend for keys matching the api_cache prefix pattern
     * and counts them. Handles missing cache backend gracefully.
     *
     * @return int|string  Number of cached entries or 'N/A' if unavailable
     */
    protected function getCachedEntryCount(): int|string
    {
        try {
            $cacheStore = config('cache.default');

            if ($cacheStore === 'redis') {
                return $this->countRedisKeys();
            } elseif ($cacheStore === 'memcached') {
                // Memcached doesn't support key scanning, return N/A
                return 'N/A';
            } else {
                // For file and database caches, return N/A
                return 'N/A';
            }
        } catch (\Exception $e) {
            Log::warning('Failed to count cached entries', [
                'error' => $e->getMessage(),
            ]);
            return 'N/A';
        }
    }

    /**
     * Count keys in Redis matching the API cache prefix pattern.
     *
     * Uses Redis SCAN command to efficiently count keys without blocking.
     *
     * @return int  Count of matching keys
     */
    protected function countRedisKeys(): int
    {
        try {
            $redis = Cache::getRedis();
            $connection = $redis->connection();

            $cursor = 0;
            $pattern = Cache::getPrefix() . self::CACHE_PREFIX . ':*';
            $count = 0;

            do {
                $result = $connection->scan($cursor, ['match' => $pattern, 'count' => 100]);

                if ($result !== false) {
                    [$cursor, $keys] = $result;
                    $count += count($keys);
                }
            } while ($cursor !== 0 && $cursor !== '0');

            return $count;
        } catch (\Exception $e) {
            Log::warning('Failed to count Redis keys', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Display cache statistics in a formatted table.
     *
     * Uses Symfony Console table component to display statistics
     * with clear formatting and alignment.
     *
     * @param array $stats  Statistics array from getCacheStatistics()
     * @return void
     */
    protected function displayStatisticsTable(array $stats): void
    {
        $this->line('');
        $this->line('<info>API Cache Statistics</info>');
        $this->line(str_repeat('━', 50));

        // Create a table for display
        $table = new Table($this->output);
        $table->setHeaders(['Metric', 'Value']);
        $table->setRows([
            ['Total Requests', $this->formatStatistic($stats['requests'])],
            ['Cache Hits', $this->formatStatistic($stats['hits']) . ' (' . $this->formatPercentage($stats['hit_rate']) . ')'],
            ['Cache Misses', $this->formatStatistic($stats['misses']) . ' (' . $this->formatPercentage(100 - $stats['hit_rate']) . ')'],
            ['Cached Entries', $this->formatStatistic($stats['cached_entries'])],
        ]);

        $table->render();

        $this->line(str_repeat('━', 50));
        $this->line('');
    }

    /**
     * Format a statistic value for display.
     *
     * Converts numbers to comma-separated format. Handles N/A values.
     *
     * @param int|string $value  The statistic value
     * @return string  Formatted statistic value
     */
    protected function formatStatistic(int|string $value): string
    {
        if ($value === 'N/A' || !is_numeric($value)) {
            return 'N/A';
        }

        return number_format((int) $value);
    }

    /**
     * Format a percentage value for display.
     *
     * @param float|int $percentage  The percentage value
     * @return string  Formatted percentage with % sign
     */
    protected function formatPercentage(float|int $percentage): string
    {
        if (is_string($percentage) || !is_numeric($percentage)) {
            return 'N/A';
        }

        return number_format((float) $percentage, 2) . '%';
    }
}
