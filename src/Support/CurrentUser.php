<?php

namespace DevZone\LogMonitor\Support;

/**
 * The id of the user already resolved for this request, if any.
 *
 * app('auth') is the AuthManager, which only forwards hasUser() and id() to
 * the default guard through __call, so the check has to run on the guard
 * itself. hasUser() never touches the session or the database: a request
 * that never authenticated reports no user rather than triggering a lookup.
 */
final class CurrentUser
{
    /**
     * @param  mixed  $auth  the AuthManager, or a guard
     * @return int|string|null
     */
    public static function id($auth)
    {
        try {
            if (!is_object($auth)) {
                return null;
            }
            $guard = method_exists($auth, 'guard') ? $auth->guard() : $auth;
            if (!is_object($guard) || !method_exists($guard, 'hasUser') || !$guard->hasUser()) {
                return null;
            }
            $id = $guard->id();

            return is_int($id) || is_string($id) ? $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
