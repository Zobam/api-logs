<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Cache TTL
    |--------------------------------------------------------------------------
    |
    | The time-to-live (in seconds) for cached API responses.
    | This controls how long cached data is considered fresh before
    | being considered expired and requiring a refresh from the external API.
    |
    | Default: 3600 seconds (1 hour)
    |
    */

    'ttl' => env('API_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Log Retention Days
    |--------------------------------------------------------------------------
    |
    | The number of days to retain API request logs before deletion.
    | Logs older than this period will be removed by the logs:clean command
    | and by the automated daily scheduler.
    |
    | Default: 30 days
    |
    */

    'log_retention_days' => env('LOG_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for external API requests.
    | If an external API request takes longer than this duration,
    | the connection will be terminated.
    |
    | Default: 10 seconds
    |
    */

    'timeout' => env('EXTERNAL_API_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | External API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for external API endpoints that the application calls.
    | Each API can have its own base_url and authentication credentials.
    |
    | Supported APIs can be added here with their respective configuration.
    |
    */

    'external_apis' => [

        'weather' => [
            'base_url' => env('WEATHER_API_URL'),
            'api_key' => env('WEATHER_API_KEY'),
        ],

        'news' => [
            'base_url' => env('NEWS_API_URL'),
            'api_key' => env('NEWS_API_KEY'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Warming Endpoints
    |--------------------------------------------------------------------------
    |
    | List of external API endpoints to pre-populate when running
    | the cache:warm-api command. This improves initial response times
    | for frequently accessed data.
    |
    | Endpoints should be specified as comma-separated values in the
    | CACHE_WARM_ENDPOINTS environment variable.
    |
    | Example: "weather/current,news/headlines,stocks/trending"
    |
    */

    'warm_endpoints' => array_filter(
        explode(',', env('CACHE_WARM_ENDPOINTS', ''))
    ),

];
