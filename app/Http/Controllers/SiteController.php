<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Response;

class SiteController extends Controller
{
    public function show(Request $request, string $path = null)
    {
        $locale = $request->route('locale') ?? 'uk';
        $path = $path ? '/' . trim($path, '/') . '/' : '/';

        // Cache on path+locale for speed
        $cacheKey = "route:{$locale}:{$path}";
        return Cache::remember($cacheKey, 60, function () use ($locale, $path) {

            // Try Page first
            $page = Page::where('path', $path)->first();
            if ($page) {
                $data = $page->toViewData($locale);
                abort_if($data['noindex'] ?? false, 404); // optional: or just set meta robots noindex
                return response()->view('page', $data);
            }

            // Try Post
            $post = Post::where('path', $path)->first();
            if ($post) {
                $data = $post->toViewData($locale);
                return response()->view('post', $data);
            }

            abort(404);
        });
    }

    public function sitemap(Request $request)
    {
        $urls = Cache::remember('sitemap:urls', 60, function () {
            $base = config('app.url');
            $items = [];

            foreach (Page::all() as $p) {
                $items[] = $base . rtrim($p->path, '/');
                $items[] = $base . '/ru' . rtrim($p->path, '/');
            }
            foreach (Post::all() as $p) {
                $items[] = $base . rtrim($p->path, '/');
                $items[] = $base . '/ru' . rtrim($p->path, '/');
            }
            return $items;
        });

        $xml = view('sitemap', ['urls' => $urls])->render();
        return Response::make($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(Request $request)
    {
        $txt = "User-agent: *\nAllow: /\n\nSitemap: " . rtrim(config('app.url'), '/') . "/sitemap.xml\n";
        return Response::make($txt, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
