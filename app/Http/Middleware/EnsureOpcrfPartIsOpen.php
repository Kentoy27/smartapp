<?php

namespace App\Http\Middleware;

use App\Models\OpcrfSchedule;
use App\Support\OpcrfAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side OPCRF Part access.
 *
 * The parts grid on the staff page decides whether to render an Access
 * button; this decides whether the Part may actually be opened. A hidden
 * button is not access control — someone can always type a URL — so every
 * Part route runs this, and the answer comes from the same
 * App\Support\OpcrfAccess the grid used, evaluated against the server clock
 * at the moment of the request.
 *
 * The only way in is a schedule that is enabled and currently open, or a Part
 * the user has already submitted: closing a deadline stops new work without
 * locking a user out of the form they already handed in.
 *
 * An administrator has no Parts of their own to fill in, so they are refused
 * here too rather than being quietly given a staff page.
 */
class EnsureOpcrfPartIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if($user === null, 403);
        abort_if($user->hasAdminAccess(), 404);

        // Read off the route rather than taking it as a middleware argument:
        // only the guard itself decides which Part is on trial, and a
        // hand-written argument could disagree with the route it is guarding.
        $partNumber = (int) $request->route('part');

        // A Part outside 1..4 is not a Part — 404 rather than a message, so
        // the route surface does not widen by typing a number. The route
        // constrains this too, but the rule is restated here because this
        // class is the actual boundary.
        abort_unless(in_array($partNumber, OpcrfSchedule::parts(), true), 404);

        if (! OpcrfAccess::canAccess($user, $partNumber)) {
            $state = OpcrfAccess::partState($user, $partNumber);

            // Redirect rather than a bare 403: the reason belongs on the
            // parts grid, where the user can see every Part and its state at
            // once instead of only the one they asked for.
            return redirect()
                ->route('opcrf.index')
                ->with('opcrfPartNotice', [
                    'status' => $state['status'],
                    'message' => $state['message'],
                    'detail' => $state['detail'],
                ]);
        }

        return $next($request);
    }
}
