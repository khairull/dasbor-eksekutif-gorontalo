<?php

namespace App\Connectors;

use App\Models\SumberData;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Membuat klien HTTP untuk sebuah sumber data, lengkap dengan autentikasi token.
 * Token hanya dipakai di server; tidak pernah dikirim ke peramban.
 */
class HttpFactory
{
    public static function for(SumberData $s): PendingRequest
    {
        $req = Http::baseUrl(rtrim($s->base_url, '/'))
            ->acceptJson()
            ->withUserAgent('DasborEksekutifGorontalo/1.0 (+data terbuka)')
            ->timeout((int) $s->opsi('timeout', 60))
            ->connectTimeout(15)
            ->retry((int) $s->opsi('retry', 3), 800, throw: false);

        return self::applyAuth($req, $s);
    }

    public static function applyAuth(PendingRequest $req, SumberData $s): PendingRequest
    {
        $token = $s->token;
        if (! $token) {
            return $req;
        }

        return match ($s->auth_type) {
            'bearer' => $req->withToken($token),
            'header' => $req->withHeaders([$s->auth_name ?: 'X-API-Key' => $token]),
            'query' => $req->withQueryParameters([$s->auth_name ?: 'api_key' => $token]),
            default => $req,
        };
    }

    /** Header & query autentikasi untuk Http::pool (pool membuat request baru). */
    public static function authParts(SumberData $s): array
    {
        $token = $s->token;
        if (! $token) {
            return [[], []];
        }

        return match ($s->auth_type) {
            'bearer' => [['Authorization' => 'Bearer '.$token], []],
            'header' => [[$s->auth_name ?: 'X-API-Key' => $token], []],
            'query' => [[], [$s->auth_name ?: 'api_key' => $token]],
            default => [[], []],
        };
    }
}
