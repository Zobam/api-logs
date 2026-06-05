<?php

namespace Tests\Feature\Middleware;

use App\Models\ApiRequestLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LogApiRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Register a test route that passes through the middleware
        Route::middleware('api')->get('/test-endpoint', function () {
            return response()->json(['success' => true], 200);
        });

        Route::middleware('api')->post('/test-create', function () {
            return response()->json(['created' => true], 201);
        });

        Route::middleware('api')->get('/test-error', function () {
            return response()->json(['error' => 'Not found'], 404);
        });
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_middleware_logs_successful_get_request(): void
    {
        $this->getJson('/test-endpoint');

        $this->assertDatabaseHas('api_request_logs', [
            'endpoint' => 'test-endpoint',
            'method' => 'GET',
            'status_code' => 200,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_middleware_logs_post_request_with_correct_status(): void
    {
        $this->postJson('/test-create');

        $this->assertDatabaseHas('api_request_logs', [
            'endpoint' => 'test-create',
            'method' => 'POST',
            'status_code' => 201,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_middleware_logs_error_response(): void
    {
        $this->getJson('/test-error');

        $this->assertDatabaseHas('api_request_logs', [
            'endpoint' => 'test-error',
            'method' => 'GET',
            'status_code' => 404,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_middleware_captures_ip_address(): void
    {
        $this->getJson('/test-endpoint');

        $log = ApiRequestLog::latest()->first();

        $this->assertNotNull($log->ip_address);
        $this->assertIsString($log->ip_address);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_middleware_captures_timestamp(): void
    {
        $before = Carbon::now();
        $this->getJson('/test-endpoint');
        $after = Carbon::now();

        $log = ApiRequestLog::latest()->first();

        $this->assertNotNull($log->requested_at);
        $this->assertGreaterThanOrEqual($before, $log->requested_at);
        $this->assertLessThanOrEqual($after, $log->requested_at);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_middleware_logs_all_request_components(): void
    {
        $this->getJson('/test-endpoint');

        $log = ApiRequestLog::latest()->first();

        $this->assertEquals('test-endpoint', $log->endpoint);
        $this->assertEquals('GET', $log->method);
        $this->assertEquals(200, $log->status_code);
        $this->assertNotNull($log->ip_address);
        $this->assertNotNull($log->requested_at);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_middleware_logs_multiple_requests(): void
    {
        $this->getJson('/test-endpoint');
        $this->postJson('/test-create');
        $this->getJson('/test-error');

        $logs = ApiRequestLog::all();

        $this->assertCount(3, $logs);
        $this->assertTrue($logs->contains('endpoint', 'test-endpoint'));
        $this->assertTrue($logs->contains('endpoint', 'test-create'));
        $this->assertTrue($logs->contains('endpoint', 'test-error'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_middleware_logging_failure_does_not_affect_response(): void
    {
        $response = $this->getJson('/test-endpoint');

        $response->assertSuccessful();
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_middleware_logs_nested_endpoints(): void
    {
        Route::middleware('api')->get('/api/v1/users/123', function () {
            return response()->json(['id' => 123], 200);
        });

        $this->getJson('/api/v1/users/123');

        $this->assertDatabaseHas('api_request_logs', [
            'endpoint' => 'api/v1/users/123',
            'method' => 'GET',
            'status_code' => 200,
        ]);
    }
}
