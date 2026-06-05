<?php

namespace Tests\Feature\Console;

use App\Models\ApiRequestLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CleanOldLogsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_deletes_logs_older_than_default_retention_period(): void
    {
        // Arrange: Create logs with different ages
        // Logs older than 30 days (should be deleted)
        Carbon::setTestNow(Carbon::now()->subDays(40));
        ApiRequestLog::factory()->count(3)->create();

        Carbon::setTestNow(Carbon::now()->subDays(35));
        ApiRequestLog::factory()->count(2)->create();

        // Logs within 30 days (should be kept)
        Carbon::setTestNow(Carbon::now()->subDays(20));
        ApiRequestLog::factory()->count(4)->create();

        Carbon::setTestNow(Carbon::now()->subDays(10));
        ApiRequestLog::factory()->count(3)->create();

        // Reset time to now
        Carbon::setTestNow(Carbon::now());

        // Act: Run the command
        $this->artisan('logs:clean')
            ->expectsOutput('Deleted 5 log entries older than 30 days.')
            ->assertSuccessful();

        // Assert: Verify only recent logs remain
        $this->assertEquals(7, ApiRequestLog::count());
        $this->assertEquals(0, ApiRequestLog::where('requested_at', '<', Carbon::now()->subDays(30))->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_respects_custom_days_option(): void
    {
        // Arrange: Create logs with different ages
        // Logs older than 60 days (should be deleted)
        Carbon::setTestNow(Carbon::now()->subDays(70));
        ApiRequestLog::factory()->count(2)->create();

        // Logs between 30 and 60 days (should be kept with --days=60)
        Carbon::setTestNow(Carbon::now()->subDays(45));
        ApiRequestLog::factory()->count(3)->create();

        // Recent logs (should be kept)
        Carbon::setTestNow(Carbon::now()->subDays(15));
        ApiRequestLog::factory()->count(4)->create();

        // Reset time to now
        Carbon::setTestNow(Carbon::now());

        // Act: Run command with custom retention period
        $this->artisan('logs:clean', ['--days' => 60])
            ->expectsOutput('Deleted 2 log entries older than 60 days.')
            ->assertSuccessful();

        // Assert: Verify correct logs remain
        $this->assertEquals(7, ApiRequestLog::count());
        $this->assertEquals(0, ApiRequestLog::where('requested_at', '<', Carbon::now()->subDays(60))->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_respects_custom_days_option_with_different_values(): void
    {
        // Arrange: Create logs with specific ages
        Carbon::setTestNow(Carbon::now()->subDays(20));
        ApiRequestLog::factory()->count(2)->create();

        Carbon::setTestNow(Carbon::now()->subDays(10));
        ApiRequestLog::factory()->count(3)->create();

        Carbon::setTestNow(Carbon::now()->subDays(5));
        ApiRequestLog::factory()->count(4)->create();

        // Reset time to now
        Carbon::setTestNow(Carbon::now());

        // Act: Delete logs older than 7 days
        $this->artisan('logs:clean', ['--days' => 7])
            ->expectsOutput('Deleted 5 log entries older than 7 days.')
            ->assertSuccessful();

        // Assert: Only logs from last 7 days remain
        $this->assertEquals(4, ApiRequestLog::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_displays_correct_deletion_count(): void
    {
        // Arrange: Create old logs
        Carbon::setTestNow(Carbon::now()->subDays(40));
        ApiRequestLog::factory()->count(10)->create();

        Carbon::setTestNow(Carbon::now());

        // Act & Assert: Verify output message
        $this->artisan('logs:clean')
            ->expectsOutput('Deleted 10 log entries older than 30 days.')
            ->assertSuccessful();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_displays_zero_when_no_logs_deleted(): void
    {
        // Arrange: Create only recent logs
        Carbon::setTestNow(Carbon::now()->subDays(10));
        ApiRequestLog::factory()->count(5)->create();

        Carbon::setTestNow(Carbon::now());

        // Act & Assert: Verify zero deletion message
        $this->artisan('logs:clean')
            ->expectsOutput('Deleted 0 log entries older than 30 days.')
            ->assertSuccessful();

        // Assert: All logs still exist
        $this->assertEquals(5, ApiRequestLog::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_handles_database_errors_gracefully(): void
    {
        // Arrange: Create logs
        Carbon::setTestNow(Carbon::now()->subDays(40));
        ApiRequestLog::factory()->count(3)->create();

        Carbon::setTestNow(Carbon::now());

        // Mock database error by closing the connection
        DB::disconnect();

        // Act: Run command expecting failure
        $this->artisan('logs:clean')
            ->assertFailed();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_preserves_exact_cutoff_date_logs(): void
    {
        // Arrange: Create logs at exact cutoff (30 days ago)
        $cutoffDate = Carbon::now()->subDays(30);

        // Log exactly at cutoff (should be kept - boundary condition)
        Carbon::setTestNow($cutoffDate);
        ApiRequestLog::factory()->create();

        // Log one second before cutoff (should be deleted)
        Carbon::setTestNow($cutoffDate->copy()->subSecond());
        ApiRequestLog::factory()->create();

        // Log one second after cutoff (should be kept)
        Carbon::setTestNow($cutoffDate->copy()->addSecond());
        ApiRequestLog::factory()->create();

        // Reset time
        Carbon::setTestNow(Carbon::now());

        // Act: Run command
        $this->artisan('logs:clean')
            ->expectsOutput('Deleted 1 log entries older than 30 days.')
            ->assertSuccessful();

        // Assert: Only the log before cutoff was deleted
        $this->assertEquals(2, ApiRequestLog::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_works_with_large_number_of_logs(): void
    {
        // Arrange: Create many old logs
        Carbon::setTestNow(Carbon::now()->subDays(40));
        ApiRequestLog::factory()->count(100)->create();

        // Create some recent logs
        Carbon::setTestNow(Carbon::now()->subDays(10));
        ApiRequestLog::factory()->count(50)->create();

        Carbon::setTestNow(Carbon::now());

        // Act: Run command
        $this->artisan('logs:clean')
            ->expectsOutput('Deleted 100 log entries older than 30 days.')
            ->assertSuccessful();

        // Assert: Only recent logs remain
        $this->assertEquals(50, ApiRequestLog::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_command_validates_different_retention_periods(): void
    {
        // Test with 1 day retention
        Carbon::setTestNow(Carbon::now()->subDays(2));
        ApiRequestLog::factory()->count(2)->create();

        Carbon::setTestNow(Carbon::now());
        ApiRequestLog::factory()->count(3)->create();

        Carbon::setTestNow(Carbon::now());

        $this->artisan('logs:clean', ['--days' => 1])
            ->expectsOutput('Deleted 2 log entries older than 1 days.')
            ->assertSuccessful();

        $this->assertEquals(3, ApiRequestLog::count());
    }

    protected function tearDown(): void
    {
        // Ensure Carbon test time is cleared after each test
        Carbon::setTestNow();
        parent::tearDown();
    }
}
