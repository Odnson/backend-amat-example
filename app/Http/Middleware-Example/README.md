# 🔒 Middleware Example

Folder ini berisi **contoh struktur Middleware** untuk referensi kontributor.

> **⚠️ CATATAN**: File middleware sensitif tidak di-include di repository publik untuk alasan keamanan.

## 📋 Middleware yang Tersedia

### Public (Tersedia di Repository)
| Middleware | Deskripsi |
|------------|-----------|
| `Authenticate.php` | Laravel default authentication |
| `EncryptCookies.php` | Enkripsi cookies |
| `TrimStrings.php` | Trim whitespace dari input |
| `VerifyCsrfToken.php` | CSRF protection |
| `RedirectIfAuthenticated.php` | Redirect jika sudah login |

### Private (Tidak di Repository)
| Middleware | Deskripsi |
|------------|-----------|
| `JwtMiddleware.php` | JWT token validation |
| `ValidateApiOrigin.php` | API origin validation |
| `SecureApiResponse.php` | Secure API response headers |
| `CheckAdminLevel.php` | Admin level authorization |
| `AdminMiddleware.php` | Admin authentication |
| `LogAdminActivity.php` | Admin activity logging |

## 🔧 Cara Membuat Middleware

### 1. Generate Middleware
```bash
php artisan make:middleware YourMiddleware
```

### 2. Struktur Dasar Middleware
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class YourMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Logic sebelum request diproses
        
        // Lanjutkan ke middleware/controller berikutnya
        $response = $next($request);
        
        // Logic setelah response (optional)
        
        return $response;
    }
}
```

### 3. Register Middleware

Di `app/Http/Kernel.php`:

```php
// Route middleware (untuk route tertentu)
protected $routeMiddleware = [
    'your.middleware' => \App\Http\Middleware\YourMiddleware::class,
];

// Middleware groups
protected $middlewareGroups = [
    'api' => [
        // ...
        \App\Http\Middleware\YourMiddleware::class,
    ],
];
```

### 4. Gunakan di Routes
```php
// Single middleware
Route::get('/protected', [Controller::class, 'method'])
    ->middleware('your.middleware');

// Multiple middleware
Route::middleware(['auth:api', 'your.middleware'])->group(function () {
    Route::get('/protected', [Controller::class, 'method']);
});
```

## 📝 Contoh Middleware

### Authentication Check
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckAuthenticated
{
    public function handle(Request $request, Closure $next)
    {
        if (!auth()->check()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }
            return redirect()->route('login');
        }

        return $next($request);
    }
}
```

### Role Check
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = auth()->user();
        
        if (!$user || !in_array($user->role, $roles)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden'
                ], 403);
            }
            abort(403, 'Unauthorized action.');
        }

        return $next($request);
    }
}

// Usage: ->middleware('role:admin,moderator')
```

### API Rate Limiting
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ApiRateLimit
{
    public function handle(Request $request, Closure $next, $maxAttempts = 60)
    {
        $key = 'rate_limit:' . $request->ip();
        $attempts = Cache::get($key, 0);
        
        if ($attempts >= $maxAttempts) {
            return response()->json([
                'success' => false,
                'message' => 'Too many requests'
            ], 429);
        }
        
        Cache::put($key, $attempts + 1, 60); // 60 seconds
        
        return $next($request);
    }
}
```

### CORS Middleware
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class HandleCors
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        
        return $response;
    }
}
```

## 🔐 Security Best Practices

### 1. Validasi Input
```php
// Selalu validasi dan sanitize input
$input = $request->input('data');
$sanitized = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
```

### 2. Gunakan Environment Variables
```php
// ❌ Jangan hardcode secrets
$secret = 'my-secret-key';

// ✅ Gunakan env()
$secret = env('APP_SECRET');
```

### 3. Log Security Events
```php
use Illuminate\Support\Facades\Log;

Log::warning('Unauthorized access attempt', [
    'ip' => $request->ip(),
    'user_agent' => $request->userAgent(),
    'path' => $request->path(),
]);
```

### 4. Handle Exceptions Gracefully
```php
try {
    // Your logic
} catch (\Exception $e) {
    Log::error('Middleware error: ' . $e->getMessage());
    
    if ($request->expectsJson()) {
        return response()->json([
            'success' => false,
            'message' => 'An error occurred'
        ], 500);
    }
    
    abort(500);
}
```

## 📝 Contoh File

Lihat file contoh di folder ini:
- `JwtMiddleware.example.php` - Contoh JWT validation middleware
- `CheckRole.example.php` - Contoh role-based authorization
