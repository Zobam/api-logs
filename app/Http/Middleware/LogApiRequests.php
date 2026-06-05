<?php

namespace App\Http\Middleware;

use App\Models\ApiRequestLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class LogApiRequests
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }

    /**
     * Terminate the request after the response is sent.
     * This implements Laravel's terminating middleware pattern.
     *
     * @param  Request  $request
     * @param  SymfonyResponse  $response
     * @return void
     */
    public function terminate(Request $request, SymfonyResponse $response): void
    {
        $this->logRequest($request, $response);
    }

    /**
     * Log the request metadata to the database.
     *
     * @param  Request  $request
     * @param  SymfonyResponse  $response
     * @return void
     */
    protected function logRequest(Request $request, SymfonyResponse $response): void
    {
        try {
            ApiRequestLog::create([
                'endpoint' => $request->path(),
                'method' => $request->method(),
                'status_code' => $response->getStatusCode(),
                'ip_address' => $request->ip(),
                'requested_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log API request', [
                'error' => $e->getMessage(),
                'endpoint' => $request->path(),
                'exception' => $e,
            ]);
        }
    }
}
