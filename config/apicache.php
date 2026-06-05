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
    | Configuration for the OpenWeatherMap API.
    | The API key is passed as a query parameter (APPID or appid).
    |
    | OpenWeatherMap Free Tier: 1,000 calls/day
    | Signup: https://openweathermap.org/appid
    |
    */

    'external_apis' => [

        'weather' => [
            'base_url' => env('WEATHER_API_URL', 'https://api.openweathermap.org/data/2.5'),
            'api_key' => env('WEATHER_API_KEY'),
            'key_param' => env('WEATHER_API_KEY_PARAM', 'APPID'),  // Query parameter name for API key
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Warming Endpoints
    |--------------------------------------------------------------------------
    |
    | List of weather API endpoints to pre-populate when running
    | the cache:warm-api command. This improves initial response times
    | for frequently accessed data.
    |
    | Endpoints should be specified as comma-separated values in the
    | CACHE_WARM_ENDPOINTS environment variable.
    |
    | Example: "weather/weather?q=London,weather/weather?q=Paris,weather/forecast?q=Tokyo"
    |
    */

    'warm_endpoints' => array_filter(
        explode(',', env('CACHE_WARM_ENDPOINTS', ''))
    ),

];
