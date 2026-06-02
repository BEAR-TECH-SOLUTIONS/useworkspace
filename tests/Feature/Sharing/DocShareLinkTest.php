<?php

namespace Tests\Feature\Sharing;

use App\Models\Docs\Doc;
use App\Models\User;
use App\Models\Vault\ShareLink;
use Illuminate\Support\Carbon;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Doc snapshots: BlockNote/Tiptap content lands verbatim, except mention
 * `userId` props are nulled (display name kept). Spec v2 §"Public payload
 * shapes / doc".
 */
class DocShareLinkTest extends TestCase
{
    public function test_owner_can_share_a_doc_and_userid_in_mentions_is_stripped(): void
    {
        [$owner, $doc] = $this->seedDocWithMention();

        $response = $this->actingAs($owner)
            ->postJson('/api/v1/share-links', [
                'resource_type' => 'doc',
                'resource_id' => $doc->id,
                'token_hash' => hash('sha256', 'tok-doc'),
                'expires_at' => Carbon::now()->addDay()->toIso8601String(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('share_link.resource_type', 'doc');

        $payload = ShareLink::query()
            ->where('token_hash', hash('sha256', 'tok-doc'))
            ->firstOrFail()
            ->snapshot_payload;

        $this->assertSame($doc->id, $payload['id']);
        $this->assertSame('Onboarding', $payload['title']);

        // Walk the snapshot content tree — every mention node should have
        // userId nulled but the display name preserved.
        $mention = $payload['content'][0]['content'][0];
        $this->assertSame('mention', $mention['type']);
        $this->assertNull($mention['props']['userId']);
        $this->assertSame('Charlie', $mention['props']['name']);
    }

    /**
     * @return array{0: User, 1: Doc}
     */
    private function seedDocWithMention(): array
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $doc = Doc::create([
            'project_id' => $project->id,
            'title' => 'Onboarding',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'mention', 'props' => ['userId' => 999, 'name' => 'Charlie']],
                        ['type' => 'text', 'text' => ' welcome aboard'],
                    ],
                ],
            ],
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);

        return [$owner, $doc];
    }
}
