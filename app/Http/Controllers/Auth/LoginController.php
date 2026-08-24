<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // The entry screen asks for a "Username", but accounts live in the stock
        // users table which is keyed by email. Accept either: anything shaped
        // like an email is matched on email, everything else on name.
        $field = filter_var($credentials['username'], FILTER_VALIDATE_EMAIL) ? 'email' : 'name';

        $attempt = Auth::attempt(
            [$field => $credentials['username'], 'password' => $credentials['password']],
            $request->boolean('remember')
        );

        if (! $attempt) {
            throw ValidationException::withMessages([
                'username' => 'These credentials do not match our records.',
            ]);
        }

        // Standard fixation guard: a fresh session id once the identity changes.
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
