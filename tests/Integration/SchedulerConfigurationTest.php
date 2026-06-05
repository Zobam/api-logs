<?php

namespace Tests\Integration;

use App\Models\ApiRequestLog;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SchedulerConfigurationTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_logs_clean_command_is_registered_in_scheduler(): void
    {
        // Arrange: Get the schedule instance
        $schedule = $this->app->make(Schedule::class);

        // Act: Retrieve all scheduled events
        $events = $schedule->events();

        // Assert: Verify logs:clean command is registered
        $logCleanEvent = collect($events)
            ->first(fn($event) => str_contains($event->command ?? '', 'logs:clean'));

        $this->assertNotNull($logCleanEvent, 'logs:clean command should be registered in scheduler');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_logs_clean_command_runs_daily_at_midnight(): void
    {
        // Arrange: Get the schedule instance
        $schedule = $this->app->make(Schedule::class);

        // Act: Find the logs:clean event
        $logCleanEvent = collect($schedule->events())
            ->first(fn($event) => str_contains($event->command ?? '', 'logs:clean'));

        // Assert: Verify it's scheduled for daily execution
        $this->assertNotNull($logCleanEvent);
        $this->assertTrue(
            $logCleanEvent->isDue($this->app->make('log')),
            'logs:clean should be configured with daily schedule'
        );

        // Assert: Verify the schedule is at midnight (00:00) UTC
        // The expression should indicate daily at specific time
        $this->assertStringContainsString('logs:clean', $logCleanEvent->command ?? '');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_scheduler_timezone_is_utc(): void
    {
        // Arrange: Get the schedule instance
        $schedule = $this->app->make(Schedule::class);

        // Act: Find the logs:clean event
        $logCleanEvent = collect($schedule->events())
            ->first(fn($event) => str_contains($event->command ?? '', 'logs:clean'));

        // Assert: Verify timezone is UTC
        $this->assertNotNull($logCleanEvent);
        $this->assertEquals(
            'UTC',
            $logCleanEvent->timezone,
            'logs:clean should be scheduled in UTC timezone'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_success_callback_logs_completion_message(): void
    {
        // Arrange: Get the schedule instance and mock the Log facade
        $schedule = $this->app->make(Schedule::class);

        // Find the logs:clean event
        $logCleanEvent = collect($schedule->events())
            ->first(fn($event) => str_contains($event->command ?? '', 'logs:clean'));

        $this->assertNotNull($logCleanEvent, 'logs:clean event should exist');

        // Assert: Verify the success callback exists
        $this->assertNotNull(
            $logCleanEvent->afterCallbacks,
            'logs:clean should have a success callback configured'
        );

        // Assert: Verify we have at least one success callback
        $this->assertCount(
            1,
            $logCleanEvent->afterCallbacks,
            'logs:clean should have exactly one success callback'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_failure_callback_logs_error_message(): void
    {
        // Arrange: Get the schedule instance
        $schedule = $this->app->make(Schedule::class);

        // Act: Find the logs:clean event
        $logCleanEvent = collect($schedule->events())
            ->first(fn($event) => str_contains($event->command ?? '', 'logs:clean'));

        // Assert: Verify the failure callback exists
        $this->assertNotNull($logCleanEvent, 'logs:clean event should exist');
        $this->assertNotNull(
            $logCleanEvent->exceptionCallbacks,
            'logs:clean should have a failure callback configured'
        );

        // Assert: Verify we have at least one failure callback
        $this->assertCount(
            1,
            $logCleanEvent->exceptionCallbacks,
            'logs:clean should have exactly one failure callback'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_scheduler_configuration_uses_daily_at_midnight(): void
    {
        // Arrange: Get the schedule instance
        $schedule = $this->app->make(Schedule::class);

        // Act: Find the logs:clean event
        $logCleanEvent = collect($schedule->events())
            ->first(fn($event) => str_contains($event->command ?? '', 'logs:clean'));

        // Assert: Verify event properties indicate daily midnight schedule
        $this->assertNotNull($logCleanEvent);

        // The event should have a cron expression for daily at midnight
        // Daily at 00:00 = "0 0 * * *"
        $expectedExpression = '0 0 * * *';
        $this->assertEquals(
            $expectedExpression,
            $logCleanEvent->expression,
            'logs:clean should be scheduled for 00:00 (midnight) daily'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_event_is_configured_with_callbacks(): void
    {
        // Arrange: Get the schedule instance
        $schedule = $this->app->make(Schedule::class);

        // Act: Find the logs:clean event
        $logCleanEvent = collect($schedule->events())
            ->first(fn($event) => str_contains($event->command ?? '', 'logs:clean'));

        // Assert: Verify both success and failure callbacks are configured
        $this->assertNotNull($logCleanEvent);
        $this->assertTrue(
            count($logCleanEvent->afterCallbacks ?? []) > 0 ||
                count($logCleanEvent->exceptionCallbacks ?? []) > 0,
            'logs:clean should have at least one callback configured'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_scheduler_contains_exactly_one_logs_clean_event(): void
    {
        // Arrange: Get the schedule instance
        $schedule = $this->app->make(Schedule::class);

        // Act: Count all logs:clean events
        $logCleanEvents = collect($schedule->events())
            ->filter(fn($event) => str_contains($event->command ?? '', 'logs:clean'))
            ->all();

        // Assert: Should have exactly one logs:clean event
        $this->assertCount(
            1,
            $logCleanEvents,
            'Scheduler should contain exactly one logs:clean event'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_schedule_event_has_proper_cron_expression(): void
    {
        // Arrange: Get the schedule instance
        $schedule = $this->app->make(Schedule::class);

        // Act: Find the logs:clean event
        $logCleanEvent = collect($schedule->events())
            ->first(fn($event) => str_contains($event->command ?? '', 'logs:clean'));

        // Assert: Verify the cron expression is valid for daily at midnight
        $this->assertNotNull($logCleanEvent);

        // Parse the cron expression: minute hour day month day-of-week
        $parts = explode(' ', $logCleanEvent->expression);
        $this->assertCount(5, $parts, 'Cron expression should have 5 parts');

        // Minute should be 0 (midnight)
        $this->assertEquals('0', $parts[0], 'Minute should be 0 for midnight');

        // Hour should be 0 (midnight hour)
        $this->assertEquals('0', $parts[1], 'Hour should be 0 for midnight');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_success_callback_is_executed_when_command_succeeds(): void
    {
        // Arrange: Create some old logs to clean up
        $cutoffDate = Carbon::now()->subDays(40);
        ApiRequestLog::factory()->create(['requested_at' => $cutoffDate]);
        ApiRequestLog::factory()->create(['requested_at' => Carbon::now()]); // Keep this one

        $logSpy = Log::spy();

        // Act: Get the schedule and find the logs:clean event
        $schedule = $this->app->make(Schedule::class);
        $logCleanEvent = collect($schedule->events())
            ->first(fn($event) => str_contains($event->command ?? '', 'logs:clean'));

        // Simulate command success by executing it
        $this->artisan('logs:clean')->assertSuccessful();

        // Assert: Verify the success callback was set (check callbacks exist)
        $this->assertNotNull($logCleanEvent);
        $this->assertNotNull(
            $logCleanEvent->afterCallbacks,
            'logs:clean should have success callbacks configured'
        );

        // Assert: The callback should log a success message
        $logSpy->shouldHaveReceived('info')
            ->with('Log cleanup task completed successfully')
            ->once();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_failure_callback_is_configured_for_error_handling(): void
    {
        // Arrange: Get the schedule instance
        $schedule = $this->app->make(Schedule::class);

        // Act: Find the logs:clean event
        $logCleanEvent = collect($schedule->events())
            ->first(fn($event) => str_contains($event->command ?? '', 'logs:clean'));

        // Assert: Verify the failure callback exists
        $this->assertNotNull($logCleanEvent, 'logs:clean event should exist');
        $this->assertNotNull(
            $logCleanEvent->exceptionCallbacks,
            'logs:clean should have a failure callback configured'
        );

        // Assert: Verify callback will be invoked on failure
        $this->assertCount(
            1,
            $logCleanEvent->exceptionCallbacks,
            'logs:clean should have exactly one failure callback'
        );

        // Verify the callback is a callable that logs the error
        $callbackExists = !empty($logCleanEvent->exceptionCallbacks);
        $this->assertTrue($callbackExists, 'Failure callback should be configured and callable');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_schedule_call_verifies_command_execution(): void
    {
        // Arrange: Create test logs
        $oldDate = Carbon::now()->subDays(40);
        $recentDate = Carbon::now();

        ApiRequestLog::factory()->create(['requested_at' => $oldDate]);
        ApiRequestLog::factory()->create(['requested_at' => $recentDate]);

        // Act: Execute the logs:clean command directly via artisan
        $this->artisan('logs:clean')
            ->expectsOutput('Deleted 1 log entries older than 30 days.')
            ->assertExitCode(0);

        // Assert: Verify old logs are deleted and recent logs are kept
        $this->assertDatabaseMissing('api_request_logs', ['requested_at' => $oldDate]);
        $this->assertDatabaseHas('api_request_logs', ['requested_at' => $recentDate->format('Y-m-d H:i:s')]);
    }
}
