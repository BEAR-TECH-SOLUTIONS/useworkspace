<?php

namespace Tests\Feature\Feedback;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\UserFactory;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // throttle:5,1 buckets in the cache; flush so cross-test traffic
        // doesn't trip the limit unexpectedly.
        Cache::flush();
    }

    public function test_authenticated_user_can_submit_feedback(): void
    {
        config(['app.feedback_ip_pepper' => 'test-pepper']);

        $user = UserFactory::create();

        $response = $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->postJson('/api/v1/feedback', [
                'category' => 'bug',
                'message' => 'The kanban board flickers when I drag cards quickly.',
                'debug' => [
                    'app' => ['name' => 'TeamCore', 'version' => '1.4.0'],
                    'os' => ['name' => 'macOS', 'arch' => 'arm64', 'target' => 'darwin'],
                    'locale' => 'en-US',
                    'timezone' => 'Europe/Madrid',
                ],
            ]);

        $response->assertCreated()
            ->assertJsonStructure(['feedback' => ['id', 'created_at']])
            ->assertJsonMissingPath('feedback.message');

        $row = DB::table('feedback')->where('id', $response->json('feedback.id'))->first();
        $this->assertNotNull($row);
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('bug', $row->category);
        $this->assertSame(hash('sha256', '203.0.113.42test-pepper'), $row->ip_hash);
    }

    public function test_feedback_without_debug_bundle_persists_null(): void
    {
        $user = UserFactory::create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/feedback', [
                'category' => 'feature',
                'message' => 'Add a dark mode toggle to the sidebar please.',
            ])
            ->assertCreated();

        $row = DB::table('feedback')->where('id', $response->json('feedback.id'))->first();
        $this->assertNull($row->debug);
    }

    public function test_message_under_minimum_length_is_rejected(): void
    {
        $user = UserFactory::create();

        $this->actingAs($user)
            ->postJson('/api/v1/feedback', [
                'category' => 'bug',
                'message' => 'too short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    public function test_message_over_maximum_length_is_rejected(): void
    {
        $user = UserFactory::create();

        $this->actingAs($user)
            ->postJson('/api/v1/feedback', [
                'category' => 'bug',
                'message' => str_repeat('a', 4001),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    public function test_unknown_category_is_rejected(): void
    {
        $user = UserFactory::create();

        $this->actingAs($user)
            ->postJson('/api/v1/feedback', [
                'category' => 'praise',
                'message' => 'I love the UI, especially the new animations.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category']);
    }

    public function test_sixth_rapid_submission_is_throttled(): void
    {
        $user = UserFactory::create();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)
                ->postJson('/api/v1/feedback', [
                    'category' => 'other',
                    'message' => "Submission number {$i} from the burst test.",
                ])
                ->assertCreated();
        }

        $this->actingAs($user)
            ->postJson('/api/v1/feedback', [
                'category' => 'other',
                'message' => 'Sixth submission should trip the throttle.',
            ])
            ->assertStatus(429);
    }

    public function test_unauthenticated_post_is_rejected(): void
    {
        $this->postJson('/api/v1/feedback', [
            'category' => 'bug',
            'message' => 'Anonymous feedback should not be accepted.',
        ])->assertStatus(401);
    }
}
