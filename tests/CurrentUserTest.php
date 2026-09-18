<?php

namespace DevZone\LogMonitor\Tests;

use DevZone\LogMonitor\Support\CurrentUser;
use PHPUnit\Framework\TestCase;

final class CurrentUserTest extends TestCase
{
    private function guard($id, bool $resolved = true)
    {
        return new class($id, $resolved) {
            private $id;

            private $resolved;

            public function __construct($id, bool $resolved)
            {
                $this->id = $id;
                $this->resolved = $resolved;
            }

            public function hasUser(): bool
            {
                return $this->resolved;
            }

            public function id()
            {
                return $this->id;
            }
        };
    }

    /** Mirrors Illuminate\Auth\AuthManager: hasUser() and id() only exist through __call. */
    private function manager($guard)
    {
        return new class($guard) {
            private $guard;

            public function __construct($guard)
            {
                $this->guard = $guard;
            }

            public function guard($name = null)
            {
                return $this->guard;
            }

            public function __call($method, $parameters)
            {
                return $this->guard->{$method}(...$parameters);
            }
        };
    }

    public function test_it_reads_the_user_through_the_auth_manager(): void
    {
        $this->assertSame(42, CurrentUser::id($this->manager($this->guard(42))));
        $this->assertSame('a1b2', CurrentUser::id($this->manager($this->guard('a1b2'))));
    }

    public function test_it_accepts_a_guard_directly(): void
    {
        $this->assertSame(7, CurrentUser::id($this->guard(7)));
    }

    public function test_no_resolved_user_reports_nothing(): void
    {
        $this->assertNull(CurrentUser::id($this->manager($this->guard(42, false))));
        $this->assertNull(CurrentUser::id($this->manager($this->guard(['not', 'scalar']))));
        $this->assertNull(CurrentUser::id(null));
        $this->assertNull(CurrentUser::id($this->manager(new \stdClass())));
    }

    public function test_a_throwing_guard_never_breaks_the_request(): void
    {
        $manager = new class() {
            public function guard()
            {
                throw new \RuntimeException('guard not defined');
            }
        };

        $this->assertNull(CurrentUser::id($manager));
    }
}
