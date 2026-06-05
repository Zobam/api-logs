<?php

namespace App\Console\Commands;

use App\Services\ExternalApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class WarmApiCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:warm-api';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Warm the API cache by fetching data for predefined endpoints';

    /**
     * Create a new command instance.
     *
     * @param  ExternalApiService  $apiService
     */
    public function __construct(protected ExternalApiService $apiService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * Fetches data from predefined external API endpoints and populates the cache
     * with fresh data. Continues execution on individual endpoint failures.
     *
     * @return int  Command exit code (0 for success, 1 for failure)
     */
    public function handle(): int
    {
        try {
            $endpoints = $this->getEndpointsToWarm();

            if (empty($endpoints)) {
                $this->warn('No endpoints configured for cache warming.');
                return Command::SUCCESS;
            }

            $this->info('Warming API cache...');
            $this->newLine();

            $successCount = 0;
            $failureCount = 0;

            foreach ($endpoints as $endpoint) {
                $endpoint = trim($endpoint);

                if (empty($endpoint)) {
                    continue;
                }

                try {
                    $startTime = microtime(true);

                    // Fetch data from the API service (will cache it)
                    $data = $this->apiService->fetch($endpoint, []);

                    $duration = round((microtime(true) - $startTime) * 1000, 2);

                    if ($data !== null) {
                        $this->line("<info>✓</info> {$endpoint} ({$duration}ms)");
                        $successCount++;
                    } else {
                        $this->line("<error>✗</error> {$endpoint} (API returned no data)");
                        $failureCount++;
                    }
                } catch (\Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException $e) {
                    $this->line("<error>✗</error> {$endpoint} (API unavailable)");
                    $failureCount++;
                    Log::warning("Cache warming failed for endpoint: {$endpoint}", [
                        'error' => $e->getMessage(),
                    ]);
                } catch (\Exception $e) {
                    $this->line("<error>✗</error> {$endpoint} (Error: {$e->getMessage()})");
                    $failureCount++;
                    Log::warning("Cache warming failed for endpoint: {$endpoint}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->newLine();

            $totalEndpoints = $successCount + $failureCount;
            if ($totalEndpoints > 0) {
                $this->info("Successfully warmed {$successCount} of {$totalEndpoints} endpoints.");

                Log::info('Cache warming completed', [
                    'success' => $successCount,
                    'failure' => $failureCount,
                    'total' => $totalEndpoints,
                ]);
            }

            return $failureCount === 0 ? Command::SUCCESS : Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Cache warming failed: {$e->getMessage()}");
            Log::error('Cache warming encountered an error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Get the list of endpoints to warm from configuration.
     *
     * Reads from the CACHE_WARM_ENDPOINTS environment variable (comma-separated values)
     * via the config file.
     *
     * @return array  Array of endpoint strings
     */
    protected function getEndpointsToWarm(): array
    {
        return config('apicache.warm_endpoints', []);
    }
}
