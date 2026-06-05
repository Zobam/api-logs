<?php

namespace Tests\Unit\Models;

use App\Models\ApiRequestLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiRequestLogTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_model_has_correct_fillable_attributes(): void
    {
        $fillable = ['endpoint', 'method', 'status_code', 'ip_address', 'requested_at'];
        $this->assertEquals($fillable, (new ApiRequestLog())->getFillable());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_can_create_model_with_mass_assignment(): void
    {
        $data = [
            'endpoint' => '/api/users',
            'method' => 'GET',
            'status_code' => 200,
            'ip_address' => '192.168.1.1',
            'requested_at' => Carbon::now(),
        ];

        $log = ApiRequestLog::create($data);

        $this->assertDatabaseHas('api_request_logs', $data);
        $this->assertEquals($data['endpoint'], $log->endpoint);
        $this->assertEquals($data['method'], $log->method);
        $this->assertEquals($data['status_code'], $log->status_code);
        $this->assertEquals($data['ip_address'], $log->ip_address);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_requested_at_is_cast_to_datetime(): void
    {
        $timestamp = Carbon::now();
        $log = ApiRequestLog::factory()->requestedAt($timestamp)->create();

        $this->assertInstanceOf(Carbon::class, $log->requested_at);
        $this->assertEquals($timestamp->format('Y-m-d H:i:s'), $log->requested_at->format('Y-m-d H:i:s'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_status_code_is_cast_to_integer(): void
    {
        $log = ApiRequestLog::factory()->create(['status_code' => '200']);

        $this->assertIsInt($log->status_code);
        $this->assertEquals(200, $log->status_code);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_older_than_scope_returns_logs_before_date(): void
    {
        $now = Carbon::now();
        $pastDate = $now->clone()->subDays(10);
        $futureDate = $now->clone()->addDays(10);

        // Create logs at different times
        ApiRequestLog::factory()->requestedAt($pastDate)->create();
        ApiRequestLog::factory()->requestedAt($now)->create();
        ApiRequestLog::factory()->requestedAt($futureDate)->create();

        // Query for logs older than now
        $oldLogs = ApiRequestLog::olderThan($now)->get();

        $this->assertCount(1, $oldLogs);
        $this->assertTrue($oldLogs->first()->requested_at->isBefore($now));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_older_than_scope_with_multiple_records(): void
    {
        $cutoffDate = Carbon::now()->subDays(30);
        $olderDate = $cutoffDate->clone()->subDays(5);
        $newerDate = $cutoffDate->clone()->addDays(5);

        // Create 5 old logs
        ApiRequestLog::factory(5)->requestedAt($olderDate)->create();
        // Create 3 new logs
        ApiRequestLog::factory(3)->requestedAt($newerDate)->create();

        // Query for logs older than cutoff date
        $oldLogs = ApiRequestLog::olderThan($cutoffDate)->get();

        $this->assertCount(5, $oldLogs);
        $oldLogs->each(function ($log) use ($cutoffDate) {
            $this->assertTrue($log->requested_at->isBefore($cutoffDate));
        });
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_older_than_scope_excludes_logs_on_or_after_date(): void
    {
        $cutoffDate = Carbon::now()->subDays(30);
        $beforeCutoff = $cutoffDate->clone()->subSeconds(1);
        $onCutoff = $cutoffDate->clone();
        $afterCutoff = $cutoffDate->clone()->addSeconds(1);

        ApiRequestLog::factory()->requestedAt($beforeCutoff)->create();
        ApiRequestLog::factory()->requestedAt($onCutoff)->create();
        ApiRequestLog::factory()->requestedAt($afterCutoff)->create();

        $oldLogs = ApiRequestLog::olderThan($cutoffDate)->get();

        $this->assertCount(1, $oldLogs);
        $this->assertTrue($oldLogs->first()->requested_at->isBefore($cutoffDate));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_can_use_factory_with_successful_state(): void
    {
        $log = ApiRequestLog::factory()->successful()->create();

        $this->assertEquals(200, $log->status_code);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_can_use_factory_with_error_state(): void
    {
        $log = ApiRequestLog::factory()->error()->create();

        $this->assertContains($log->status_code, [400, 401, 403, 404, 500, 502, 503]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_can_specify_endpoint_in_factory(): void
    {
        $endpoint = '/api/custom/endpoint';
        $log = ApiRequestLog::factory()->forEndpoint($endpoint)->create();

        $this->assertEquals($endpoint, $log->endpoint);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_can_specify_method_in_factory(): void
    {
        $method = 'POST';
        $log = ApiRequestLog::factory()->withMethod($method)->create();

        $this->assertEquals($method, $log->method);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_timestamps_are_automatically_set(): void
    {
        $log = ApiRequestLog::factory()->create();

        $this->assertNotNull($log->created_at);
        $this->assertNotNull($log->updated_at);
        $this->assertInstanceOf(Carbon::class, $log->created_at);
        $this->assertInstanceOf(Carbon::class, $log->updated_at);
    }
}
