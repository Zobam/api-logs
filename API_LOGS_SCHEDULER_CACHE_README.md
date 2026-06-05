# API Logs Scheduler Cache Feature

A comprehensive logging, caching, and automated maintenance system for Laravel 13 applications. This feature tracks all API requests, automatically manages log retention, and provides intelligent caching for external API responses.

## Table of Contents

- [Feature Overview](#feature-overview)
- [Architecture](#architecture)
- [Setup Instructions](#setup-instructions)
- [Configuration Reference](#configuration-reference)
- [API Endpoints](#api-endpoints)
- [Artisan Commands](#artisan-commands)
- [Troubleshooting](#troubleshooting)

## Feature Overview

The API Logs Scheduler Cache feature consists of three main subsystems:

### 1. API Request Logging

Automatically tracks all incoming API requests with detailed metadata for auditing and troubleshooting. Every request is logged with:

- Request endpoint and HTTP method
- Response status code
- Client IP address
- Request timestamp (accurate to the second)

### 2. Automated Log Cleanup

Scheduled background task that automatically removes old log entries to prevent database bloat. Prevents unbounded database growth while maintaining configurable retention periods.

### 3. External API Response Caching

Redis/Memcached-based caching layer that stores external API responses with automatic expiration. Reduces external API costs and improves application performance through intelligent cache management.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    HTTP Request Flow                        │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Request → LogApiRequests Middleware → Application         │
│                                   ↓                         │
│                         ApiRequestLog Model                 │
│                                   ↓                         │
│                    Database (api_request_logs)              │
│                                                             │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                   Caching Flow                              │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Cache Request → ExternalApiService                        │
│                   ↓                                         │
│            Cache Hit? → Return Cached Data                 │
│                   ↓                                         │
│            Cache Miss → Fetch External API                 │
│                   ↓                                         │
│            Store in Cache → Return Data                    │
│                   ↓                                         │
│        Redis/Memcached (TTL: 1 hour)                       │
│                                                             │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│              Scheduled Cleanup Flow                         │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Daily at 00:00 UTC → CleanOldLogsCommand                  │
│                   ↓                                         │
│  Delete logs > 30 days old                                 │
│                   ↓                                         │
│  Log deletion count                                        │
│                                                             │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│              Manual Management Commands                     │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  logs:clean         → Delete old logs manually             │
│  cache:clear-api    → Clear all API cache                  │
│  cache:warm-api     → Pre-populate cache                   │
│  cache:stats        → Display cache statistics             │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

## Setup Instructions

### Prerequisites

- Laravel 13 application
- MySQL/PostgreSQL database
- Redis or Memcached (for caching layer)
- PHP 8.2 or higher

### Installation Steps

#### 1. Run Database Migrations

```bash
php artisan migrate
```

This creates the `api_request_logs` table with optimized indexes for efficient querying:

- `requested_at`: For date-range queries during cleanup
- `endpoint, requested_at`: For analyzing specific endpoint usage
- `ip_address`: For IP-based querying

#### 2. Configure Environment Variables

Add the following to your `.env` file:

```bash
# Cache Configuration
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_CACHE_DB=1

# API Cache Settings
API_CACHE_TTL=3600              # Cache expiration in seconds (1 hour)
LOG_RETENTION_DAYS=30           # Days to retain API logs

# External API Configuration
WEATHER_API_URL=https://api.weather.com
WEATHER_API_KEY=your_api_key_here
NEWS_API_URL=https://api.news.com
NEWS_API_KEY=your_api_key_here

# External API Options
EXTERNAL_API_TIMEOUT=10         # Request timeout in seconds

# Cache Warming
CACHE_WARM_ENDPOINTS=weather/current,news/headlines,stocks/trending
```

#### 3. Set Up Scheduler

The Laravel scheduler runs periodic tasks. Ensure your server's crontab includes:

```bash
* * * * * cd /path/to/your/project && php artisan schedule:run >> /dev/null 2>&1
```

For local development, test the scheduler with:

```bash
php artisan schedule:work
```

This displays scheduled tasks and runs them in real-time for testing.

#### 4. Verify Installation

```bash
# Check scheduler configuration
php artisan schedule:list

# Test cache command
php artisan cache:stats

# Test log cleanup command (dry run recommended first)
php artisan logs:clean --help
```

## Configuration Reference

### Cache Configuration (`CACHE_STORE`)

**Options**: `redis`, `memcached`, `database`, `file`

**Recommended**: `redis` or `memcached` for production

```bash
# Redis
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_CACHE_DB=1

# Memcached
CACHE_STORE=memcached
MEMCACHED_HOST=127.0.0.1
MEMCACHED_PORT=11211
```

### API Cache TTL (`API_CACHE_TTL`)

**Default**: 3600 seconds (1 hour)
**Units**: Seconds
**Purpose**: Time-to-live for all cached API responses

```bash
API_CACHE_TTL=3600    # 1 hour
API_CACHE_TTL=1800    # 30 minutes
API_CACHE_TTL=86400   # 1 day
```

### Log Retention (`LOG_RETENTION_DAYS`)

**Default**: 30 days
**Purpose**: How long to retain API request logs before deletion

```bash
LOG_RETENTION_DAYS=30   # Keep 30 days of logs
LOG_RETENTION_DAYS=60   # Keep 60 days of logs
LOG_RETENTION_DAYS=7    # Keep 7 days of logs (minimal retention)
```

### External API Configuration

Configure endpoints and credentials for external APIs:

```bash
WEATHER_API_URL=https://api.weather.com
WEATHER_API_KEY=sk_live_weather_key_123456

NEWS_API_URL=https://api.news.com
NEWS_API_KEY=sk_live_news_key_789012

EXTERNAL_API_TIMEOUT=10    # Connection timeout in seconds
```

### Cache Warming Endpoints (`CACHE_WARM_ENDPOINTS`)

Comma-separated list of endpoints to pre-populate on startup:

```bash
CACHE_WARM_ENDPOINTS=weather/current,news/headlines,stocks/trending
```

These endpoints are fetched and cached when running `php artisan cache:warm-api`.

### Complete .env Example

See `.env.example` in the project root for a complete template with all supported environment variables and descriptive comments.

## API Endpoints

### GET `/api/cache/{resource}`

Retrieve cached data for a specific external API resource.

**Parameters**:

- `resource` (path parameter): The external API resource path (e.g., `weather/current`, `news/headlines`)
- Query parameters: Forwarded to the external API

**Request Example**:

```http
GET /api/cache/weather/current?city=London&units=metric HTTP/1.1
Host: your-app.com
Accept: application/json
```

**Success Response (HTTP 200)**:

```json
{
    "data": {
        "temperature": 15,
        "conditions": "Partly cloudy",
        "humidity": 65
    },
    "cached": true,
    "expires_at": "2024-01-15T14:30:00Z"
}
```

**Response Headers**:

- `X-Cache-Hit`: `true` or `false` - Indicates if data was served from cache
- `X-Cache-TTL`: Number of seconds until cache expiration
- `Content-Type`: `application/json`

**Error Response - Service Unavailable (HTTP 503)**:

When external API is unavailable and no cache exists:

```json
{
    "error": "External API is currently unavailable",
    "message": "The requested data could not be retrieved. Please try again later."
}
```

**Error Response - Invalid Resource (HTTP 404)**:

When the requested resource doesn't exist:

```json
{
    "error": "Resource not found",
    "message": "The requested resource does not exist."
}
```

**Example Usage with cURL**:

```bash
# Retrieve cached weather data
curl -i "http://localhost/api/cache/weather/current?city=London"

# Response headers show cache status
# X-Cache-Hit: true
# X-Cache-TTL: 3456
```

## Artisan Commands

### `logs:clean` - Delete Old API Logs

Manually trigger cleanup of old API request logs.

**Signature**:

```bash
logs:clean {--days= : Number of days to retain logs}
```

**Usage**:

```bash
# Use default retention period (30 days)
php artisan logs:clean

# Specify custom retention period (keep 60 days)
php artisan logs:clean --days=60

# Keep only 7 days of logs
php artisan logs:clean --days=7
```

**Output Example**:

```
Deleted 1,234 log entries older than 30 days.
```

**Behavior**:

- Deletes log entries with `requested_at` timestamp older than the specified days
- Uses the default `LOG_RETENTION_DAYS` environment variable if `--days` is not specified
- Performs chunked deletion to avoid memory issues with large datasets
- Logs the deletion count to the application log file
- Returns exit code 0 on success, 1 on failure

**Scheduled Execution**:

This command runs automatically daily at 00:00 UTC (configurable in `routes/console.php`).

### `cache:clear-api` - Clear All API Cache

Remove all cached API responses from the cache store.

**Signature**:

```bash
cache:clear-api
```

**Usage**:

```bash
php artisan cache:clear-api
```

**Output Example**:

```
API cache cleared successfully.
```

**Behavior**:

- Removes all API cache entries (keys matching `api_cache:*` pattern)
- Clears cache statistics counters (hits, misses, requests)
- Does NOT affect other application caches
- Useful for troubleshooting cache issues or forcing fresh data
- Returns exit code 0 on success, 1 on failure

**When to Use**:

- After detecting stale or incorrect cached data
- When external API schema changes
- During development/testing to reset cache state
- When debugging cache behavior

### `cache:warm-api` - Pre-populate Cache

Fetch fresh data for predefined endpoints and populate the cache.

**Signature**:

```bash
cache:warm-api
```

**Usage**:

```bash
php artisan cache:warm-api
```

**Output Example**:

```
Warming API cache...
✓ weather/current (250ms)
✓ news/headlines (320ms)
✗ stocks/trending (timeout)

Successfully warmed 2 of 3 endpoints.
```

**Behavior**:

- Reads endpoint list from `CACHE_WARM_ENDPOINTS` environment variable
- Fetches each endpoint sequentially from external API
- Stores responses in cache with full TTL
- Displays progress with timing for each endpoint
- Continues on individual endpoint failures
- Logs results to application log
- Returns exit code 0 on success, 1 if any endpoint fails

**Configuration**:

Set endpoints in `.env`:

```bash
CACHE_WARM_ENDPOINTS=weather/current,news/headlines,stocks/trending
```

**When to Use**:

- During application startup
- After cache was cleared to restore frequently-used data
- Before expected traffic spikes to ensure cache is populated
- In deployment pipelines post-deployment

### `cache:stats` - Display Cache Statistics

View cache performance metrics and statistics.

**Signature**:

```bash
cache:stats
```

**Usage**:

```bash
php artisan cache:stats
```

**Output Example**:

```
API Cache Statistics
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Total Requests:  1,250
Cache Hits:        950  (76.0%)
Cache Misses:      300  (24.0%)
Cached Entries:     45
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

**Displayed Metrics**:

- **Total Requests**: Total number of cache operations
- **Cache Hits**: Successful cache retrievals
- **Cache Misses**: Cache misses that triggered external API calls
- **Hit Rate**: Percentage of successful cache hits
- **Cached Entries**: Number of currently cached items

**Behavior**:

- Retrieves statistics from cache backend (Redis/Memcached)
- Calculates hit rate percentage as `(hits / (hits + misses)) * 100`
- Shows "N/A" for unavailable statistics
- Does not reset statistics (for persistent monitoring)
- Returns exit code 0 on success, 1 on failure

**When to Use**:

- Monitor cache effectiveness
- Identify optimization opportunities
- Track performance improvements after adjustments
- Troubleshoot potential caching issues

## Troubleshooting

### Common Issues and Solutions

#### Issue: Logs Not Being Recorded

**Symptoms**: API requests don't appear in `api_request_logs` table

**Solutions**:

1. **Verify middleware is registered**

    ```bash
    # Check bootstrap/app.php or app/Http/Kernel.php
    # LogApiRequests should be registered in the 'api' middleware group
    ```

2. **Check database connectivity**

    ```bash
    php artisan tinker
    >>> \App\Models\ApiRequestLog::count();
    ```

3. **Verify table exists**

    ```bash
    php artisan migrate:status
    ```

4. **Check error logs**
    ```bash
    tail -f storage/logs/laravel.log
    ```

#### Issue: Cache Not Working

**Symptoms**: `X-Cache-Hit` always shows `false`, or cache endpoint returns errors

**Solutions**:

1. **Verify cache configuration**

    ```bash
    # Check .env file
    echo $CACHE_STORE
    ```

2. **Test cache backend connectivity**

    ```bash
    # For Redis
    redis-cli ping  # Should return PONG

    # For Memcached
    echo "stats" | nc 127.0.0.1 11211
    ```

3. **Check cache driver in use**

    ```bash
    php artisan tinker
    >>> config('cache.default');
    ```

4. **Clear cache and retry**

    ```bash
    php artisan cache:clear-api
    ```

5. **Check external API connectivity**
    ```bash
    # Test with curl
    curl -i "https://your-external-api.com/endpoint"
    ```

#### Issue: Scheduler Not Running

**Symptoms**: Logs are not being cleaned automatically, `logs:clean` appears in schedule but doesn't execute

**Solutions**:

1. **Verify crontab is configured**

    ```bash
    # Check if cron job exists
    crontab -l | grep artisan
    ```

2. **Add cron job if missing**

    ```bash
    # Edit crontab
    crontab -e

    # Add this line:
    * * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
    ```

3. **Test scheduler locally**

    ```bash
    # Watch scheduler execute tasks in real-time
    php artisan schedule:work
    ```

4. **Check scheduler log**

    ```bash
    tail -f storage/logs/laravel.log | grep -i schedule
    ```

5. **Verify command exists**
    ```bash
    php artisan logs:clean --help
    ```

#### Issue: External API Timeout

**Symptoms**: Cache endpoint returns 503 error, timeout errors in logs

**Solutions**:

1. **Increase timeout value**

    ```bash
    # In .env
    EXTERNAL_API_TIMEOUT=30  # Increase from default 10 seconds
    ```

2. **Check external API status**

    ```bash
    # Test external API with curl
    curl -v "https://your-external-api.com/endpoint"
    ```

3. **Check network connectivity**

    ```bash
    # Test basic network connectivity
    ping your-external-api.com
    ```

4. **Review error logs**
    ```bash
    tail -f storage/logs/laravel.log
    ```

#### Issue: Cache Growing Too Large

**Symptoms**: Redis/Memcached memory usage increases, performance degrades

**Solutions**:

1. **Lower cache TTL**

    ```bash
    # In .env - reduce from 3600 to 1800 (30 minutes)
    API_CACHE_TTL=1800
    ```

2. **Reduce cache warming endpoints**

    ```bash
    # In .env - cache fewer endpoints
    CACHE_WARM_ENDPOINTS=weather/current,news/headlines
    ```

3. **Increase cache memory limit**
    - For Redis: Increase `maxmemory` in redis.conf
    - For Memcached: Increase `-m` flag value

4. **Check cache statistics**

    ```bash
    php artisan cache:stats
    ```

5. **Clear old entries**
    ```bash
    php artisan cache:clear-api
    ```

#### Issue: Database Growing Too Large

**Symptoms**: API logs table takes excessive disk space

**Solutions**:

1. **Lower retention period**

    ```bash
    # In .env - reduce from 30 days to 7 days
    LOG_RETENTION_DAYS=7
    ```

2. **Run log cleanup manually**

    ```bash
    php artisan logs:clean --days=14
    ```

3. **Monitor log table size**

    ```bash
    php artisan tinker
    >>> \App\Models\ApiRequestLog::count();
    >>> \Illuminate\Support\Facades\DB::table('api_request_logs')->count();
    ```

4. **Check for slow log queries**
    ```bash
    # Enable slow query log in MySQL
    # Then review storage/logs/laravel.log
    ```

#### Issue: Permission Errors When Running Commands

**Symptoms**: "Permission denied" or "Unable to write" errors

**Solutions**:

1. **Check file permissions**

    ```bash
    ls -la storage/
    ```

2. **Fix permissions**

    ```bash
    chmod -R 775 storage bootstrap/cache
    chown -R www-data:www-data storage bootstrap/cache
    ```

3. **Check command permissions**
    ```bash
    ls -la artisan
    chmod +x artisan
    ```

#### Issue: Missing Environment Variables

**Symptoms**: "Configuration key [cache.default] does not exist" or similar errors

**Solutions**:

1. **Copy .env.example**

    ```bash
    cp .env.example .env
    ```

2. **Add missing variables**

    ```bash
    # Ensure these are in .env:
    CACHE_STORE=redis
    API_CACHE_TTL=3600
    LOG_RETENTION_DAYS=30
    ```

3. **Restart application**
    ```bash
    php artisan config:cache
    ```

### Debug Mode

Enable debug logging for detailed troubleshooting:

1. **Set debug level in .env**

    ```bash
    LOG_LEVEL=debug
    ```

2. **Check detailed logs**

    ```bash
    tail -f storage/logs/laravel.log
    ```

3. **Test individual components**

    ```bash
    php artisan tinker

    # Test cache
    >>> Cache::put('test', 'value', 3600);
    >>> Cache::get('test');

    # Test external service
    >>> $service = app(\App\Services\ExternalApiService::class);
    >>> $service->fetch('weather/current');

    # Test log creation
    >>> \App\Models\ApiRequestLog::create([...]);
    ```

### Performance Optimization

1. **Add database indexes** (already included in migration)
2. **Use connection pooling** for external APIs
3. **Implement rate limiting** to prevent cache thrashing
4. **Monitor cache hit rate** regularly
5. **Archive old logs** periodically to external storage
6. **Use Redis Cluster** for high-traffic applications

### Getting Help

When reporting issues, include:

- Application logs: `storage/logs/laravel.log`
- Environment configuration: `.env` (without secrets)
- Command output with full error messages
- Database schema verification: `php artisan migrate:status`
- System information: PHP version, cache driver type

---

## Summary

This feature provides production-ready API request logging, automated maintenance, and intelligent caching for Laravel applications. All components follow Laravel conventions and best practices for reliability and performance.

For questions or issues, refer to the troubleshooting section or check the application logs in `storage/logs/laravel.log`.
