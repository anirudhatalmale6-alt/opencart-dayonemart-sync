<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyLetsyncToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('letsync.token');
        $provided = (string) $request->header('X-Letsync-Token', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
