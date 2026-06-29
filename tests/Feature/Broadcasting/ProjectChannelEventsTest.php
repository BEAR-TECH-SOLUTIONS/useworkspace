<?php

namespace Tests\Feature\Broadcasting;

use App\Events\Project\BoardCreated;
use App\Events\Project\BoardDeleted;
use App\Events\Project\BoardUpdated;
use App\Events\Project\BucketCreated;
use App\Events\Project\BucketDeleted;
use App\Events\Project\BucketUpdated;
use App\Events\Project\DocCreated;
use App\Events\Project\DocDeleted;
use App\Events\Project\DocUpdated;
use App\Events\Project\VaultCreated;
use App\Events\Project\VaultDeleted;
use App\Events\Project\VaultUpdated;
use App\Models\Docs\Doc;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Tasks\TaskBoard;
use App\Models\Vault\Vault;
use Illuminate\Support\Facades\Event;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * #211 — resource-lifecycle broadcasts on `private-project.{projectId}`.
 *
 * Each event must fire on the project channel with the documented
 * `broadcastAs` name + payload, and must carry the actor's `X-Socket-Id`
 * on its `socket` property so the broadcasting layer excludes the
 * originating client (the second subscriber still receives it).
 */
class ProjectChannelEventsTest extends TestCase
{
    private const SOCKET = '1234.5678';

    // ---- Boards -----------------------------------------------------------

    public function test_creating_a_board_broadcasts_board_created_on_the_project_channel(): void
    {
        Event::fake([BoardCreated::class]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $response = $this->actingAs($owner)
            ->withHeader('X-Socket-Id', self::SOCKET)
            ->postJson("/api/v1/projects/{$project->id}/task-boards", ['name' => 'Sprint 42'])
            ->assertCreated();

        $boardId = $response->json('board.id');

        Event::assertDispatched(BoardCreated::class, function (BoardCreated $e) use ($project, $boardId): bool {
            return $this->onProjectChannel($e, $project->id)
                && $e->broadcastAs() === 'board.created'
                && $e->broadcastWith()['board']['id'] === $boardId
                && $e->socket === self::SOCKET;
        });
    }

    public function test_updating_a_board_broadcasts_board_updated(): void
    {
        Event::fake([BoardUpdated::class]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $board = TaskBoard::create(['project_id' => $project->id, 'name' => 'Old', 'created_by' => $owner->id]);

        $this->actingAs($owner)
            ->withHeader('X-Socket-Id', self::SOCKET)
            ->patchJson("/api/v1/task-boards/{$board->id}", ['name' => 'New'])
            ->assertOk();

        Event::assertDispatched(BoardUpdated::class, function (BoardUpdated $e) use ($project, $board): bool {
            $payload = $e->broadcastWith()['board'];

            return $this->onProjectChannel($e, $project->id)
                && $e->broadcastAs() === 'board.updated'
                && $payload['id'] === $board->id
                && $payload['name'] === 'New'
                && $e->socket === self::SOCKET;
        });
    }

    public function test_deleting_a_board_broadcasts_board_deleted_with_just_the_id(): void
    {
        Event::fake([BoardDeleted::class]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $board = TaskBoard::create(['project_id' => $project->id, 'name' => 'Disposable', 'created_by' => $owner->id]);

        $this->actingAs($owner)
            ->withHeader('X-Socket-Id', self::SOCKET)
            ->deleteJson("/api/v1/task-boards/{$board->id}")
            ->assertNoContent();

        Event::assertDispatched(BoardDeleted::class, function (BoardDeleted $e) use ($project, $board): bool {
            return $this->onProjectChannel($e, $project->id)
                && $e->broadcastAs() === 'board.deleted'
                && $e->broadcastWith() === ['id' => $board->id]
                && $e->socket === self::SOCKET;
        });
    }

    // ---- Vaults -----------------------------------------------------------

    public function test_creating_a_vault_broadcasts_vault_created_with_null_wrapped_key(): void
    {
        Event::fake([VaultCreated::class]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $response = $this->actingAs($owner)
            ->withHeader('X-Socket-Id', self::SOCKET)
            ->postJson("/api/v1/projects/{$project->id}/vaults", ['name' => 'Prod secrets'])
            ->assertCreated();

        $vaultId = $response->json('vault.id');

        Event::assertDispatched(VaultCreated::class, function (VaultCreated $e) use ($project, $vaultId): bool {
            $payload = $e->broadcastWith()['vault'];

            return $this->onProjectChannel($e, $project->id)
                && $e->broadcastAs() === 'vault.created'
                && $payload['id'] === $vaultId
                // The shared channel can't personalise per recipient, so the
                // wrapped key is always null on the wire (key present though).
                && array_key_exists('my_wrapped_key', $payload)
                && $payload['my_wrapped_key'] === null
                && $e->socket === self::SOCKET;
        });
    }

    public function test_updating_a_vault_broadcasts_vault_updated(): void
    {
        Event::fake([VaultUpdated::class]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = Vault::create(['project_id' => $project->id, 'name' => 'Old', 'created_by' => $owner->id]);

        $this->actingAs($owner)
            ->withHeader('X-Socket-Id', self::SOCKET)
            ->patchJson("/api/v1/vaults/{$vault->id}", ['name' => 'New'])
            ->assertOk();

        Event::assertDispatched(VaultUpdated::class, function (VaultUpdated $e) use ($project, $vault): bool {
            $payload = $e->broadcastWith()['vault'];

            return $this->onProjectChannel($e, $project->id)
                && $e->broadcastAs() === 'vault.updated'
                && $payload['id'] === $vault->id
                && $payload['name'] === 'New'
                && $payload['my_wrapped_key'] === null;
        });
    }

    public function test_deleting_a_vault_broadcasts_vault_deleted(): void
    {
        Event::fake([VaultDeleted::class]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = Vault::create(['project_id' => $project->id, 'name' => 'Disposable', 'created_by' => $owner->id]);

        $this->actingAs($owner)
            ->withHeader('X-Socket-Id', self::SOCKET)
            ->deleteJson("/api/v1/vaults/{$vault->id}")
            ->assertNoContent();

        Event::assertDispatched(VaultDeleted::class, function (VaultDeleted $e) use ($project, $vault): bool {
            return $this->onProjectChannel($e, $project->id)
                && $e->broadcastAs() === 'vault.deleted'
                && $e->broadcastWith() === ['id' => $vault->id];
        });
    }

    // ---- Buckets ----------------------------------------------------------

    public function test_creating_a_bucket_broadcasts_bucket_created(): void
    {
        Event::fake([BucketCreated::class]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $response = $this->actingAs($owner)
            ->withHeader('X-Socket-Id', self::SOCKET)
            ->postJson("/api/v1/projects/{$project->id}/expense-buckets", ['name' => 'Infra'])
            ->assertCreated();

        $bucketId = $response->json('bucket.id');

        Event::assertDispatched(BucketCreated::class, function (BucketCreated $e) use ($project, $bucketId): bool {
            return $this->onProjectChannel($e, $project->id)
                && $e->broadcastAs() === 'bucket.created'
                && $e->broadcastWith()['bucket']['id'] === $bucketId
                && $e->socket === self::SOCKET;
        });
    }

    public function test_updating_a_bucket_broadcasts_bucket_updated(): void
    {
        Event::fake([BucketUpdated::class]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = ExpenseBucket::create(['project_id' => $project->id, 'name' => 'Old', 'currency' => 'USD', 'created_by' => $owner->id]);

        $this->actingAs($owner)
            ->withHeader('X-Socket-Id', self::SOCKET)
            ->patchJson("/api/v1/expense-buckets/{$bucket->id}", ['name' => 'New'])
            ->assertOk();

        Event::assertDispatched(BucketUpdated::class, function (BucketUpdated $e) use ($project, $bucket): bool {
            $payload = $e->broadcastWith()['bucket'];

            return $this->onProjectChannel($e, $project->id)
                && $e->broadcastAs() === 'bucket.updated'
                && $payload['id'] === $bucket->id
                && $payload['name'] === 'New';
        });
    }

    public function test_deleting_a_bucket_broadcasts_bucket_deleted(): void
    {
        Event::fake([BucketDeleted::class]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = ExpenseBucket::create(['project_id' => $project->id, 'name' => 'Disposable', 'currency' => 'USD', 'created_by' => $owner->id]);

        $this->actingAs($owner)
            ->withHeader('X-Socket-Id', self::SOCKET)
            ->deleteJson("/api/v1/expense-buckets/{$bucket->id}")
            ->assertNoContent();

        Event::assertDispatched(BucketDeleted::class, function (BucketDeleted $e) use ($project, $bucket): bool {
            return $this->onProjectChannel($e, $project->id)
                && $e->broadcastAs() === 'bucket.deleted'
                && $e->broadcastWith() === ['id' => $bucket->id];
        });
    }

    // ---- Docs -------------------------------------------------------------

    public function test_creating_a_doc_broadcasts_doc_created(): void
    {
        Event::fake([DocCreated::class]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $response = $this->actingAs($owner)
            ->withHeader('X-Socket-Id', self::SOCKET)
            ->postJson("/api/v1/projects/{$project->id}/docs", ['title' => 'Runbook'])
            ->assertCreated();

        $docId = $response->json('doc.id');

        Event::assertDispatched(DocCreated::class, function (DocCreated $e) use ($project, $docId): bool {
            return $this->onProjectChannel($e, $project->id)
                && $e->broadcastAs() === 'doc.created'
                && $e->broadcastWith()['doc']['id'] === $docId
                && $e->socket === self::SOCKET;
        });
    }

    public function test_updating_a_doc_broadcasts_doc_updated(): void
    {
        Event::fake([DocUpdated::class]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $doc = Doc::create(['project_id' => $project->id, 'title' => 'Old', 'content' => [], 'created_by' => $owner->id, 'updated_by' => $owner->id]);

        $this->actingAs($owner)
            ->withHeader('X-Socket-Id', self::SOCKET)
            ->patchJson("/api/v1/docs/{$doc->id}", ['title' => 'New'])
            ->assertOk();

        Event::assertDispatched(DocUpdated::class, function (DocUpdated $e) use ($project, $doc): bool {
            $payload = $e->broadcastWith()['doc'];

            return $this->onProjectChannel($e, $project->id)
                && $e->broadcastAs() === 'doc.updated'
                && $payload['id'] === $doc->id
                && $payload['title'] === 'New';
        });
    }

    public function test_deleting_a_doc_broadcasts_doc_deleted(): void
    {
        Event::fake([DocDeleted::class]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $doc = Doc::create(['project_id' => $project->id, 'title' => 'Disposable', 'content' => [], 'created_by' => $owner->id, 'updated_by' => $owner->id]);

        $this->actingAs($owner)
            ->withHeader('X-Socket-Id', self::SOCKET)
            ->deleteJson("/api/v1/docs/{$doc->id}")
            ->assertNoContent();

        Event::assertDispatched(DocDeleted::class, function (DocDeleted $e) use ($project, $doc): bool {
            return $this->onProjectChannel($e, $project->id)
                && $e->broadcastAs() === 'doc.deleted'
                && $e->broadcastWith() === ['id' => $doc->id];
        });
    }

    // ---- Socket exclusion -------------------------------------------------

    public function test_without_socket_id_the_event_excludes_nobody(): void
    {
        Event::fake([BoardCreated::class]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        // No X-Socket-Id header → socket stays null → every subscriber
        // (including the actor's other tabs) receives the broadcast.
        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->id}/task-boards", ['name' => 'No socket'])
            ->assertCreated();

        Event::assertDispatched(BoardCreated::class, fn (BoardCreated $e): bool => $e->socket === null);
    }

    /**
     * Assert the event publishes on exactly `private-project.{projectId}`.
     */
    private function onProjectChannel(object $event, int $projectId): bool
    {
        $channels = $event->broadcastOn();

        return count($channels) === 1
            && $channels[0]->name === 'private-project.'.$projectId;
    }
}
