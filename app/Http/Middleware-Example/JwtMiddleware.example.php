<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Exceptions\JWTException;

/**
 * JWT Middleware - Contoh Struktur
 * 
 * Middleware ini memvalidasi JWT token pada setiap request API.
 * 
 * Requires: tymon/jwt-auth package
 * Install: composer require tymon/jwt-auth
 * Setup: php artisan jwt:secret
 */
class JwtMiddleware
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
        try {
            // Coba authenticate user dari token
            $user = JWTAuth::parseToken()->authenticate();
            
            if (!$user) {
                return $this->unauthorizedResponse('User not found');
            }
            
            // Optional: Check if user is active
            if (isset($user->is_active) && !$user->is_active) {
                return $this->unauthorizedResponse('Account is deactivated');
            }
            
        } catch (TokenExpiredException $e) {
            return $this->unauthorizedResponse('Token has expired', 'TOKEN_EXPIRED');
            
        } catch (TokenInvalidException $e) {
            return $this->unauthorizedResponse('Token is invalid', 'TOKEN_INVALID');
            
        } catch (JWTException $e) {
            return $this->unauthorizedResponse('Token not provided', 'TOKEN_ABSENT');
        }

        return $next($request);
    }

    /**
     * Return unauthorized JSON response
     *
     * @param string $message
     * @param string $code
     * @return \Illuminate\Http\JsonResponse
     */
    private function unauthorizedResponse($message, $code = 'UNAUTHORIZED')
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => $code,
        ], 401);
    }
}

/*
|--------------------------------------------------------------------------
| Registration in Kernel.php
|--------------------------------------------------------------------------
|
| protected $routeMiddleware = [
|     // ...
|     'jwt.verify' => \App\Http\Middleware\JwtMiddleware::class,
|     'jwt.auth' => \App\Http\Middleware\JwtMiddleware::class,
| ];
|
|--------------------------------------------------------------------------
| Usage in Routes
|--------------------------------------------------------------------------
|
| // Single route
| Route::get('/profile', [ProfileController::class, 'show'])
|     ->middleware('jwt.verify');
|
| // Route group
| Route::middleware('jwt.verify')->group(function () {
|     Route::get('/profile', [ProfileController::class, 'show']);
|     Route::post('/profile', [ProfileController::class, 'update']);
| });
|
|--------------------------------------------------------------------------
| JWT Configuration (.env)
|--------------------------------------------------------------------------
|
| JWT_SECRET=your-secret-key-here
| JWT_TTL=60              # Token lifetime in minutes
| JWT_REFRESH_TTL=20160   # Refresh token lifetime in minutes
| JWT_BLACKLIST_ENABLED=true
|
*/
