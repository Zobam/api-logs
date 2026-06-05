# 🌤️ OpenWeatherMap Setup Guide

Quick setup guide for using OpenWeatherMap with the API Logs Scheduler Cache system.

---

## 📝 Step 1: Get Your API Key

1. Go to: **https://openweathermap.org/appid**
2. Click "**Sign Up**" (completely free)
3. Fill out the registration form
4. **Verify your email** (check spam folder if needed)
5. Log in and go to **"API keys"** tab
6. Copy your API key (looks like: `48f4977b37d1bc4ffcf95f4c4d8ea0ff`)

**Note:** It may take a few minutes for your API key to become active after registration.

---

## ⚙️ Step 2: Configure Your .env File

Open your `.env` file and add/update these lines:

```env
# Cache Configuration
CACHE_STORE=redis
API_CACHE_TTL=3600
LOG_RETENTION_DAYS=30

# OpenWeatherMap Configuration
WEATHER_API_URL=https://api.openweathermap.org/data/2.5
WEATHER_API_KEY=your_actual_api_key_here
WEATHER_API_KEY_PARAM=APPID

# Optional: Cache warming endpoints
CACHE_WARM_ENDPOINTS=weather/weather?q=London
```

**Replace `your_actual_api_key_here` with your actual API key from Step 1.**

---

## 🚀 Step 3: Test Your Setup

### Start Laravel Server

```bash
php artisan serve
```

### Test with Postman or Browser

**Request URL:**

```
GET http://localhost:8000/api/cache/weather/weather?q=London
```

**Alternative formats:**

```
# Current weather by city name
GET http://localhost:8000/api/cache/weather/weather?q=Paris,fr

# Current weather by city ID
GET http://localhost:8000/api/cache/weather/weather?id=2643743

# Current weather by coordinates
GET http://localhost:8000/api/cache/weather/weather?lat=35&lon=139

# With units (metric = Celsius, imperial = Fahrenheit)
GET http://localhost:8000/api/cache/weather/weather?q=London&units=metric
```

### Expected Response (First Call - Cache Miss)

**Status:** `200 OK`

**Headers:**

- `X-Cache-Hit: false` ← First call, not cached yet
- `X-Cache-TTL: 3600` ← Will expire in 1 hour
- `Content-Type: application/json`

**Body:**

```json
{
    "data": {
        "coord": {
            "lon": -0.1257,
            "lat": 51.5085
        },
        "weather": [
            {
                "id": 803,
                "main": "Clouds",
                "description": "broken clouds",
                "icon": "04d"
            }
        ],
        "main": {
            "temp": 289.22,
            "feels_like": 288.66,
            "temp_min": 288.31,
            "temp_max": 289.96,
            "pressure": 1011,
            "humidity": 68
        },
        "wind": {
            "speed": 2.68,
            "deg": 318
        },
        "name": "London",
        "cod": 200
    },
    "cached": false,
    "expires_at": "2024-01-15T15:30:00+00:00"
}
```

### Test Cache Hit (Second Call)

Run the **same request again** immediately:

**Expected Changes:**

- `X-Cache-Hit: true` ← Now served from cache!
- `"cached": true` in response body
- **Much faster response** (typically 5-20ms vs 200-500ms)

---

## 🌐 Available OpenWeatherMap Endpoints

### Current Weather Data

```
GET /api/cache/weather/weather
```

**Query Parameters:**

- `q` - City name (e.g., `q=London` or `q=London,uk`)
- `id` - City ID (e.g., `id=2643743`)
- `lat` & `lon` - Coordinates (e.g., `lat=35&lon=139`)
- `units` - `metric` (Celsius) or `imperial` (Fahrenheit)
- `lang` - Language code (e.g., `lang=fr`)

**Examples:**

```
GET http://localhost:8000/api/cache/weather/weather?q=Tokyo&units=metric
GET http://localhost:8000/api/cache/weather/weather?q=New York,us&units=imperial
GET http://localhost:8000/api/cache/weather/weather?lat=40.7128&lon=-74.0060
```

### 5-Day Weather Forecast

```
GET /api/cache/weather/forecast
```

**Query Parameters:**

- Same as current weather endpoint

**Example:**

```
GET http://localhost:8000/api/cache/weather/forecast?q=Paris&units=metric
```

---

## 📊 Verify Everything Works

### 1. Check Request Logging

```sql
-- View recent API requests in your database
SELECT * FROM api_request_logs ORDER BY requested_at DESC LIMIT 10;
```

You should see entries for each request made.

### 2. Check Cache Statistics

```bash
php artisan cache:stats
```

**Expected Output:**

```
API Cache Statistics
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Total Requests:    2
Cache Hits:        1  (50.0%)
Cache Misses:      1  (50.0%)
Cached Entries:    1
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

### 3. Test Cache Clearing

```bash
# Clear all cached weather data
php artisan cache:clear-api

# Now make the same request again - should be cache miss
GET http://localhost:8000/api/cache/weather/weather?q=London

# Expected: X-Cache-Hit: false
```

---

## 🎯 Common Use Cases

### Use Case 1: Dashboard with Multiple Cities

```
GET http://localhost:8000/api/cache/weather/weather?q=London&units=metric
GET http://localhost:8000/api/cache/weather/weather?q=Paris&units=metric
GET http://localhost:8000/api/cache/weather/weather?q=Tokyo&units=metric
```

Each city creates a separate cache entry. Second requests will all be cache hits.

### Use Case 2: Weather Forecast Page

```
# Current weather
GET http://localhost:8000/api/cache/weather/weather?q=London&units=metric

# 5-day forecast
GET http://localhost:8000/api/cache/weather/forecast?q=London&units=metric
```

Both endpoints are cached separately.

### Use Case 3: Multilingual Weather

```
# English (default)
GET http://localhost:8000/api/cache/weather/weather?q=London

# French
GET http://localhost:8000/api/cache/weather/weather?q=London&lang=fr

# Spanish
GET http://localhost:8000/api/cache/weather/weather?q=London&lang=es
```

Each language creates a separate cache entry.

---

## 🔧 Advanced Configuration

### Adjust Cache TTL

In `.env`:

```env
# Cache for 30 minutes instead of 1 hour
API_CACHE_TTL=1800

# Cache for 2 hours
API_CACHE_TTL=7200
```

### Pre-warm Cache for Popular Cities

In `.env`:

```env
CACHE_WARM_ENDPOINTS=weather/weather?q=London,weather/weather?q=Paris,weather/weather?q=Tokyo
```

Then run:

```bash
php artisan cache:warm-api
```

---

## ⚠️ Free Tier Limits

**OpenWeatherMap Free Account:**

- ✅ 1,000 API calls per day
- ✅ 60 calls per minute
- ✅ Current weather data
- ✅ 5-day / 3-hour forecast
- ✅ Historical data (limited)

**Tips to Stay Within Limits:**

1. Use appropriate cache TTL (default 1 hour is good)
2. Cache hit rate > 70% means you're doing great
3. Monitor usage: `php artisan cache:stats`
4. Pre-warm cache for popular locations

---

## 🐛 Troubleshooting

### Problem: "Invalid API key"

**Solution:**

- Wait 10-15 minutes after registering (activation delay)
- Check your API key is correct in `.env`
- Verify no extra spaces in `WEATHER_API_KEY`
- Restart Laravel server: `php artisan serve`

### Problem: "X-Cache-Hit always false"

**Solution:**

```bash
# Check Redis is running
redis-cli ping  # Should return PONG

# Test cache manually
php artisan tinker
Cache::put('test', 'value', 60);
Cache::get('test');  # Should return 'value'
```

### Problem: "No response or timeout"

**Solution:**

- Check internet connection
- Try the direct API URL in your browser:
    ```
    https://api.openweathermap.org/data/2.5/weather?q=London&APPID=your_key
    ```
- Increase timeout in `.env`:
    ```env
    EXTERNAL_API_TIMEOUT=30
    ```

### Problem: "Logs not appearing in database"

**Solution:**

```bash
# Run migrations
php artisan migrate

# Check middleware is active
php artisan route:list

# Test direct log creation
php artisan tinker
\App\Models\ApiRequestLog::create([
    'endpoint' => 'test',
    'method' => 'GET',
    'status_code' => 200,
    'ip_address' => '127.0.0.1',
    'requested_at' => now()
]);
```

---

## 📚 OpenWeatherMap Documentation

- **API Documentation:** https://openweathermap.org/api
- **Current Weather:** https://openweathermap.org/current
- **5-Day Forecast:** https://openweathermap.org/forecast5
- **City IDs List:** http://bulk.openweathermap.org/sample/
- **Weather Conditions:** https://openweathermap.org/weather-conditions

---

## ✅ Quick Test Checklist

- [ ] API key obtained from OpenWeatherMap
- [ ] `.env` file updated with API key
- [ ] Laravel server running (`php artisan serve`)
- [ ] First weather request returns `X-Cache-Hit: false`
- [ ] Second weather request returns `X-Cache-Hit: true`
- [ ] Database has log entries for requests
- [ ] `php artisan cache:stats` shows statistics
- [ ] Different cities create different cache entries

---

**You're all set! 🎉**

Your API Logs Scheduler Cache system is now configured with OpenWeatherMap and ready to use.
