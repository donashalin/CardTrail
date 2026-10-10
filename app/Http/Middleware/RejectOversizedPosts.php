<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's post size check, moved inside the session so an upload over PHP's post_max_size goes back to the
 * page with a message instead of a bare 413.
 */
class RejectOversizedPosts extends ValidatePostSize
{
    public function handle($request, Closure $next): Response
    {
        try {
            return parent::handle($request, fn (Request $request) => $next($request));
        } catch (PostTooLargeException) {
            return back()->with('error', 'Those files are too big to upload together. Try fewer at a time.');
        }
    }
}
