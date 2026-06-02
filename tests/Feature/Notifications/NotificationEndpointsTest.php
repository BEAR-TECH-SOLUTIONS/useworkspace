<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\UserFactory;
use Tests\TestCase;

class NotificationEndpointsTest extends TestCase
{
    public function test_list_returns_authed_users_notifications_newest_first(): void
    {
        $user = UserFactory::create();
        $other = UserFactory::create();

        $older = $this->seedNotification($user, ['title' => 'Older', 'created_at' => Carbon::now()->subHour()]);
        $newer = $this->seedNotification($user, ['title' => 'Newer', 'created_at' => Carbon::now()]);
        $this->seedNotification($other, ['title' => "Someone else's"]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/notifications')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$newer->id, $older->id], $ids);
        $this->assertSame(2, $response->json('unread_count'));
        $this->assertNull($response->json('next_cursor'));
    }

    public function test_list_respects_unread_only_filter(): void
    {
        $user = UserFactory::create();
        $this->seedNotification($user, ['is_read' => true]);
        $unread = $this->seedNotification($user, ['is_read' => false]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/notifications?unread_only=1')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$unread->id], $ids);
    }

    public function test_cursor_pagination(): void
    {
        $user = UserFactory::create();
        $created = [];
        for ($i = 0; $i < 3; $i++) {
            $created[] = $this->seedNotification($user, ['title' => "n{$i}"]);
        }

        $first = $this->actingAs($user)
            ->getJson('/api/v1/notifications?limit=2')
            ->assertOk();

        $this->assertSame(
            [$created[2]->id, $created[1]->id],
            array_column($first->json('data'), 'id'),
        );
        $this->assertSame($created[1]->id, $first->json('next_cursor'));

        $second = $this->actingAs($user)
            ->getJson('/api/v1/notifications?limit=2&cursor='.$first->json('next_cursor'))
            ->assertOk();

        $this->assertSame([$created[0]->id], array_column($second->json('data'), 'id'));
        $this->assertNull($second->json('next_cursor'));
    }

    public function test_mark_read_flips_is_read_and_returns_notification(): void
    {
        $user = UserFactory::create();
        $n = $this->seedNotification($user);

        $this->actingAs($user)
            ->postJson("/api/v1/notifications/{$n->id}/read")
            ->assertOk()
            ->assertJsonPath('notification.is_read', true);

        $this->assertTrue((bool) Notification::find($n->id)->is_read);
    }

    public function test_mark_read_on_another_users_notification_returns_404(): void
    {
        $user = UserFactory::create();
        $other = UserFactory::create();
        $n = $this->seedNotification($other);

        $this->actingAs($user)
            ->postJson("/api/v1/notifications/{$n->id}/read")
            ->assertNotFound();
    }

    public function test_mark_all_read_returns_updated_count(): void
    {
        $user = UserFactory::create();
        $this->seedNotification($user);
        $this->seedNotification($user);
        $this->seedNotification($user, ['is_read' => true]);

        $this->actingAs($user)
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('updated_count', 2);

        $this->assertSame(
            0,
            Notification::query()->where('user_id', $user->id)->where('is_read', false)->count(),
        );
    }

    public function test_unread_count_endpoint(): void
    {
        $user = UserFactory::create();
        $this->seedNotification($user);
        $this->seedNotification($user);
        $this->seedNotification($user, ['is_read' => true]);

        $this->actingAs($user)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 2);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->getJson('/api/v1/notifications/unread-count')->assertUnauthorized();
        $this->postJson('/api/v1/notifications/read-all')->assertUnauthorized();
    }

    private function seedNotification(User $user, array $overrides = []): Notification
    {
        return Notification::create(array_merge([
            'user_id' => $user->id,
            'type' => NotificationType::TaskAssigned->value,
            'title' => 'Something happened',
            'metadata' => [],
            'is_read' => false,
            'created_at' => Carbon::now(),
        ], $overrides));
    }
}
