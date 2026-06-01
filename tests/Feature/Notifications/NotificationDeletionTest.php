<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\UserFactory;
use Tests\TestCase;

class NotificationDeletionTest extends TestCase
{
    public function test_single_delete_removes_own_notification(): void
    {
        $user = UserFactory::create();
        $n = $this->seedNotification($user);

        $this->actingAs($user)
            ->deleteJson("/api/v1/notifications/{$n->id}")
            ->assertNoContent();

        $this->assertNull(Notification::find($n->id));
    }

    public function test_single_delete_on_another_users_notification_returns_404(): void
    {
        $owner = UserFactory::create();
        $attacker = UserFactory::create();
        $n = $this->seedNotification($owner);

        $this->actingAs($attacker)
            ->deleteJson("/api/v1/notifications/{$n->id}")
            ->assertNotFound();

        $this->assertNotNull(Notification::find($n->id));
    }

    public function test_bulk_delete_wipes_inbox_by_default(): void
    {
        $user = UserFactory::create();
        $other = UserFactory::create();

        $this->seedNotification($user, ['is_read' => false]);
        $this->seedNotification($user, ['is_read' => true]);
        $this->seedNotification($user, ['is_read' => false]);

        // An unrelated user's row must NOT be touched.
        $theirs = $this->seedNotification($other);

        $this->actingAs($user)
            ->deleteJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('deleted_count', 3);

        $this->assertSame(0, Notification::query()->where('user_id', $user->id)->count());
        $this->assertNotNull(Notification::find($theirs->id));
    }

    public function test_bulk_delete_with_read_only_preserves_unread(): void
    {
        $user = UserFactory::create();
        $keep = $this->seedNotification($user, ['is_read' => false]);
        $drop1 = $this->seedNotification($user, ['is_read' => true]);
        $drop2 = $this->seedNotification($user, ['is_read' => true]);

        $this->actingAs($user)
            ->deleteJson('/api/v1/notifications?read_only=1')
            ->assertOk()
            ->assertJsonPath('deleted_count', 2);

        $this->assertNotNull(Notification::find($keep->id));
        $this->assertNull(Notification::find($drop1->id));
        $this->assertNull(Notification::find($drop2->id));
    }

    public function test_cleanup_cron_only_deletes_read_older_than_seven_days(): void
    {
        $user = UserFactory::create();

        $oldRead = $this->seedNotification($user, [
            'is_read' => true,
            'created_at' => Carbon::now()->subDays(10),
        ]);
        $recentRead = $this->seedNotification($user, [
            'is_read' => true,
            'created_at' => Carbon::now()->subDays(3),
        ]);
        $oldUnread = $this->seedNotification($user, [
            'is_read' => false,
            'created_at' => Carbon::now()->subDays(30),
        ]);

        $this->artisan('notifications:cleanup')->assertExitCode(0);

        $this->assertNull(Notification::find($oldRead->id));
        $this->assertNotNull(Notification::find($recentRead->id));
        // Unread notifications are NEVER auto-deleted regardless of age —
        // the user hasn't seen them yet.
        $this->assertNotNull(Notification::find($oldUnread->id));
    }

    public function test_delete_endpoints_require_auth(): void
    {
        $user = UserFactory::create();
        $n = $this->seedNotification($user);

        $this->deleteJson("/api/v1/notifications/{$n->id}")->assertUnauthorized();
        $this->deleteJson('/api/v1/notifications')->assertUnauthorized();
    }

    private function seedNotification(User $user, array $overrides = []): Notification
    {
        return Notification::create(array_merge([
            'user_id' => $user->id,
            'type' => NotificationType::TaskAssigned->value,
            'title' => 'Something',
            'metadata' => [],
            'is_read' => false,
            'created_at' => Carbon::now(),
        ], $overrides));
    }
}
