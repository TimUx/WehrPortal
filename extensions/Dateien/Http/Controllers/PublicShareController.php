<?php

namespace Extensions\Dateien\Http\Controllers;

use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\Share;
use Extensions\Dateien\Services\ShareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public side of a link: no login, only the shared subtree, read only. Every response is
 * no-store, not indexable (global NoIndex) and has its own enforced CSP; the page asks for no
 * referrer so the token never leaves in a Referer header.
 */
class PublicShareController
{
    public function __construct(private readonly ShareService $shares) {}

    public function show(Request $request, string $token): Response
    {
        [$share, $root] = $this->shares->resolve($token);
        if (! $this->unlocked($request, $share)) {
            return $this->page('dateien::public.password', ['token' => $token, 'share' => $share, 'error' => null], 401);
        }
        $folderId = $request->query('ordner');
        $current = $root;
        if ($folderId !== null) {
            abort_unless(is_string($folderId) && ctype_digit($folderId), 404);
            $current = Node::find((int) $folderId) ?? throw new NotFoundHttpException;
        }
        $path = $this->shares->path($share, $current) ?? throw new NotFoundHttpException;
        $this->shares->touch($share);

        $children = $current->kind === 'folder' ? $this->shares->children($share, $current) : collect();

        return $this->page('dateien::public.share', [
            'token' => $token, 'share' => $share, 'current' => $current, 'path' => $path, 'children' => $children,
            'sizes' => $this->sizes($current->kind === 'folder' ? $children->pluck('id')->all() : [$current->id]),
        ]);
    }

    public function unlock(Request $request, string $token): Response
    {
        [$share] = $this->shares->resolve($token);
        abort_if($share->password_hash === null, 404);
        // Per link and address, and per link overall, so neither one client nor many can guess
        $keys = ['dateien-share-pw|'.$share->id.'|'.$request->ip() => 5, 'dateien-share-pw|'.$share->id => 30];
        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return $this->page('dateien::public.password', ['token' => $token, 'share' => $share,
                    'error' => 'Zu viele Versuche. Bitte in '.ceil(RateLimiter::availableIn($key) / 60).' Minuten erneut versuchen.'], 429);
            }
        }
        $password = $request->input('passwort');
        if (! is_string($password) || strlen($password) > 200 || ! $this->shares->checkPassword($share, $password, $request)) {
            foreach ($keys as $key => $max) {
                RateLimiter::hit($key, 900);
            }

            return $this->page('dateien::public.password', ['token' => $token, 'share' => $share, 'error' => 'Das Passwort stimmt nicht.'], 401);
        }
        RateLimiter::clear(array_key_first($keys));
        $request->session()->regenerate();
        $request->session()->put('dateien.share.'.$share->id, true);

        return redirect()->route('dateien.share', ['token' => $token]);
    }

    public function download(Request $request, string $token, int $node): Response
    {
        [$share] = $this->shares->resolve($token);
        abort_unless($this->unlocked($request, $share), 404);
        $file = Node::find($node) ?? throw new NotFoundHttpException;
        $response = $this->shares->download($share, $file, $request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function unlocked(Request $request, Share $share): bool
    {
        return $share->password_hash === null || $request->session()->get('dateien.share.'.$share->id) === true;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int> size of the newest version per file
     */
    private function sizes(array $ids): array
    {
        return DB::table('x_files_versions as v')->whereIn('v.node_id', $ids ?: [0])
            ->whereRaw('v.number = (SELECT max(number) FROM x_files_versions WHERE node_id = v.node_id)')
            ->pluck('v.size', 'v.node_id')->map(fn ($size) => (int) $size)->all();
    }

    /** @param  array<string, mixed>  $data */
    private function page(string $view, array $data, int $status = 200): Response
    {
        $nonce = Str::random(32);

        return response()->view($view, $data + ['nonce' => $nonce], $status)->withHeaders([
            'Content-Security-Policy' => "default-src 'none'; style-src 'nonce-{$nonce}'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
