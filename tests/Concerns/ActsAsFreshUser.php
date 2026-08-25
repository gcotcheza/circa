<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\User;

/**
 * A signed-in user, made fresh for the test that asks for one.
 *
 * Nearly every route in this app is behind the session guard, so a feature
 * test that signs nobody in is testing the redirect to /login rather than the
 * thing it names — and it fails in a way that reads like the feature is broken.
 * The user is created per test rather than shared, because a row left behind by
 * an earlier test is indistinguishable from one the code under test wrote.
 * Exposed as a property so a test that needs to name the owner of a row can do
 * so without signing a second user in to find one.
 */
trait ActsAsFreshUser
{
    protected User $user;

    protected function setUpActsAsFreshUser(): void
    {
        $this->user = User::factory()->create();

        $this->actingAs($this->user);
    }
}
