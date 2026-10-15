<?php

namespace App\Http\Middleware;

use App\Domain\CutiError;
use App\Domain\CutiStore;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class CutiAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            CutiError::check($request->header('X-Cuti-Client') === 'web', 'Permintaan tidak valid.', 403);
            $origin = $request->header('Origin');
            $expected = rtrim(config('app.url'), '/');
            $local = app()->environment('local', 'testing') && in_array(parse_url($origin ?? '', PHP_URL_HOST), ['localhost', '127.0.0.1'], true);
            CutiError::check(! $origin || $origin === $expected || $local, 'Asal permintaan tidak diizinkan.', 403);
            CutiError::check((int) $request->header('Content-Length', 0) <= 40960, 'Data terlalu besar.', 413);
        }
        $path = $request->path();
        if ($path === 'api/login' || $path === 'api/verify-certificate') {
            $key = $path.':'.$request->ip();
            $limit = $path === 'api/login' ? 30 : 120;
            CutiError::check(! RateLimiter::tooManyAttempts($key, $limit), 'Terlalu banyak percobaan. Coba lagi dalam 15 menit.', 429);
            RateLimiter::hit($key, 900);
        }
        if (! in_array($path, ['api/health', 'api/config', 'api/login', 'api/verify-certificate'], true)) {
            $token = $request->cookie(config('cuti.cookie'));
            CutiError::check(is_string($token) && strlen($token) === 64, 'Silakan masuk kembali.', 401);
            $hash = CutiStore::digest($token);
            $session = DB::table('cuti_sessions')->where('token', $hash)->where('expires_at', '>', now('UTC'))->first();
            $actor = $session ? app(CutiStore::class)->employee($session->employee_id) : null;
            CutiError::check($actor && $actor['active'] && empty($actor['deletedAt']), 'Sesi berakhir. Silakan masuk kembali.', 401);
            $request->attributes->set('actor', $actor);
            $request->attributes->set('session_hash', $hash);
            if (str_starts_with($path, 'api/admin')) {
                CutiError::check(in_array('ADMIN', $actor['roles'], true), 'Akses administrator diperlukan.', 403);
            }
            if ($path === 'api/me/password') {
                $key = 'password:'.$actor['id'];
                CutiError::check(! RateLimiter::tooManyAttempts($key, 10), 'Terlalu banyak percobaan. Coba lagi dalam 15 menit.', 429);
                RateLimiter::hit($key, 900);
            }
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
