<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                // Only what the sidebar needs to name the signed-in user; never
                // the password hash or remember token.
                'user' => fn() => $request->user()?->only('id', 'name', 'username'),
            ],
            'flash' => [
                'success' => fn() => $request->session()->get('success'),
                'warning' => fn() => $request->session()->get('warning'),
                'error'   => fn() => $request->session()->get('error'),
                'uploadIssueDialog' => fn() => $request->session()->get('uploadIssueDialog'),
            ],
        ]);
    }
}
