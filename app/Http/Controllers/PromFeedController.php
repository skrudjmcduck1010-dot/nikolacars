<?php

namespace App\Http\Controllers;

use App\Services\NikolaCarsPromYmlFeed;
use App\Services\PromYmlFeed;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PromFeedController extends Controller
{
    public function donorProducts(Request $request, PromYmlFeed $feed): Response
    {
        $token = config('prom.feed_token');

        if ($token && ! hash_equals((string) $token, (string) $request->query('token'))) {
            abort(403);
        }

        return response($feed->content(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    public function nikolaCarsProducts(Request $request, NikolaCarsPromYmlFeed $feed): Response
    {
        $token = config('prom.feed_token');

        if ($token && ! hash_equals((string) $token, (string) $request->query('token'))) {
            abort(403);
        }

        return response($feed->content(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }
}
