<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Basic auth untuk panel admin. Kredensial dari .env: ADMIN_USER dan ADMIN_PASSWORD_HASH (bcrypt). */
class AdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = (string) config('dasbor.admin_user');
        $hash = (string) config('dasbor.admin_password_hash');
        $ok = $hash !== '' && hash_equals($user, (string) $request->getUser())
            && password_verify((string) $request->getPassword(), $hash);

        if (! $ok) {
            return response('Perlu login admin.', 401, ['WWW-Authenticate' => 'Basic realm="Admin Dasbor", charset="UTF-8"']);
        }

        return $next($request);
    }
}
