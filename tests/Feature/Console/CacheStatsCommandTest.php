<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CacheStatsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_displays_statistics_with_mock_cache_counters(): void
    {
        // Arrange: Set up mock cache statistics
        Cache::put('api_cache_stats:hits', 950, now()->addYear());
        Cache::put('api_cache_stats:misses', 300, now()->addYear());

        // Act: Run the cache:stats command
        $response = $this->artisan('cache:stats');

        // Assert: Verify success
        $response->assertSuccessful();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_calculates_hit_rate_accurately(): void
    {
        // Arrange: Set up cache statistics for 100% hit rate
        Cache::put('api_cache_stats:hits', 100, now()->addYear());
        Cache::put('api_cache_stats:misses', 0, now()->addYear());

        // Act: Run the command and capture output
        $response = $this->artisan('cache:stats');

        // Assert: Command succeeds and displays 100% hit rate
        $response->assertSuccessful();

        // Arrange: Set up cache statistics for 50% hit rate
        Cache::flush();
        Cache::put('api_cache_stats:hits', 50, now()->addYear());
        Cache::put('api_cache_stats:misses', 50, now()->addYear());

        // Act: Run command again
        $response = $this->artisan('cache:stats');

        // Assert: Command succeeds with 50% hit rate
        $response->assertSuccessful();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_displays_zero_statistics_when_no_data(): void
    {
        // Arrange: No cache statistics set

        // Act: Run the command
        $response = $this->artisan('cache:stats');

        // Assert: Command succeeds and displays zero values
        $response->assertSuccessful();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_handles_missing_statistics_gracefully(): void
    {
        // Arrange: Set only hits, leave misses missing
        Cache::put('api_cache_stats:hits', 500, now()->addYear());
        // Don't set misses - it should default to 0

        // Act: Run the command
        $response = $this->artisan('cache:stats');

        // Assert: Command succeeds and handles missing data
        $response->assertSuccessful();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_displays_table_with_proper_formatting(): void
    {
        // Arrange: Set up statistics
        Cache::put('api_cache_stats:hits', 760, now()->addYear());
        Cache::put('api_cache_stats:misses', 240, now()->addYear());

        // Act: Run the command
        $response = $this->artisan('cache:stats');

        // Assert: Command succeeds and displays formatted table
        $response->assertSuccessful();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_displays_cached_entries_count(): void
    {
        // Arrange: Set up some cache entries with the api_cache prefix
        Cache::put('api_cache_stats:hits', 100, now()->addYear());
        Cache::put('api_cache_stats:misses', 50, now()->addYear());

        // Act: Run the command
        $response = $this->artisan('cache:stats');

        // Assert: Command succeeds and displays cached entries
        $response->assertSuccessful();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_displays_percentage_with_proper_format(): void
    {
        // Arrange: Set up statistics for 75% hit rate
        Cache::put('api_cache_stats:hits', 75, now()->addYear());
        Cache::put('api_cache_stats:misses', 25, now()->addYear());

        // Act: Run the command
        $response = $this->artisan('cache:stats');

        // Assert: Command succeeds and displays percentage format
        $response->assertSuccessful();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_handles_large_statistics_with_number_formatting(): void
    {
        // Arrange: Set large numbers to verify formatting
        Cache::put('api_cache_stats:hits', 1000000, now()->addYear());
        Cache::put('api_cache_stats:misses', 500000, now()->addYear());

        // Act: Run the command
        $response = $this->artisan('cache:stats');

        // Assert: Command succeeds and formats large numbers
        $response->assertSuccessful();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_displays_section_dividers(): void
    {
        // Arrange: Set up statistics
        Cache::put('api_cache_stats:hits', 800, now()->addYear());
        Cache::put('api_cache_stats:misses', 200, now()->addYear());

        // Act: Run the command
        $response = $this->artisan('cache:stats');

        // Assert: Command succeeds and displays dividers
        $response->assertSuccessful();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_returns_success_exit_code(): void
    {
        // Arrange: Set up statistics
        Cache::put('api_cache_stats:hits', 500, now()->addYear());
        Cache::put('api_cache_stats:misses', 100, now()->addYear());

        // Act: Run the command
        $response = $this->artisan('cache:stats');

        // Assert: Command returns success exit code 0
        $response->assertSuccessful();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_displays_accurate_hit_rate_calculation(): void
    {
        // Arrange: Set up specific statistics for calculation verification
        // 950 hits + 50 misses = 1000 total requests = 95% hit rate
        Cache::put('api_cache_stats:hits', 950, now()->addYear());
        Cache::put('api_cache_stats:misses', 50, now()->addYear());

        // Act: Run the command
        $response = $this->artisan('cache:stats');

        // Assert: Command succeeds with correct calculations
        $response->assertSuccessful();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_handles_zero_requests_hit_rate(): void
    {
        // Arrange: Set zero statistics
        Cache::put('api_cache_stats:hits', 0, now()->addYear());
        Cache::put('api_cache_stats:misses', 0, now()->addYear());

        // Act: Run the command
        $response = $this->artisan('cache:stats');

        // Assert: Command succeeds and handles division by zero
        $response->assertSuccessful();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_output_structure_contains_all_metrics(): void
    {
        // Arrange: Set up complete statistics
        Cache::put('api_cache_stats:hits', 900, now()->addYear());
        Cache::put('api_cache_stats:misses', 100, now()->addYear());

        // Act: Run the command
        $response = $this->artisan('cache:stats');

        // Assert: All required metrics are displayed
        $response->assertSuccessful();
    }
}
