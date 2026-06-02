<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ResourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Docs\StoreDocRequest;
use App\Http\Requests\Docs\UpdateDocRequest;
use App\Http\Resources\Docs\DocResource;
use App\Models\Docs\Doc;
use App\Models\Project\Project;
use App\Services\Docs\DocContentTextExtractor;
use App\Services\Permissions\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocController extends Controller
{
    public function __construct(
        private readonly PermissionService $perms,
        private readonly DocContentTextExtractor $textExtractor,
    ) {}

    /**
     * List docs in the project visible to the caller. Pattern B
     * users only see docs they hold a direct grant on — same gate as
     * the other per-project list endpoints.
     *
     * `?search=…` runs a Postgres FTS match against `content_text`
     * plus a case-insensitive match against `title`, so a client
     * can find a doc by body text or just the title substring.
     * Archived docs are always excluded from the list.
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->perms->hasAnyGrantIn($user, $project), 403);

        $query = $this->perms
            ->visibleScope($user, ResourceType::Doc, $project)
            ->where('is_archived', false);

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->whereRaw(
                    "to_tsvector('english', coalesce(content_text, '')) @@ plainto_tsquery('english', ?)",
                    [$search],
                )->orWhereRaw('lower(title) like ?', ['%'.mb_strtolower($search).'%']);
            });
        }

        $docs = $query->orderByDesc('updated_at')->get();

        return response()->json([
            'data' => DocResource::previewCollection($docs)->resolve($request),
        ]);
    }

    public function store(StoreDocRequest $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $user = $request->user();
        $content = (array) $request->input('content', []);

        $doc = Doc::create([
            'project_id' => $project->id,
            'title' => $request->string('title')->toString(),
            'content' => $content,
            'content_text' => $this->textExtractor->extract($content),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return response()->json([
            'doc' => new DocResource($doc),
        ], 201);
    }

    public function show(Request $request, Doc $doc): JsonResponse
    {
        $this->authorize('view', $doc);

        return response()->json([
            'doc' => new DocResource($doc),
        ]);
    }

    public function update(UpdateDocRequest $request, Doc $doc): JsonResponse
    {
        $this->authorize('update', $doc);

        $user = $request->user();
        $payload = ['updated_by' => $user->id];

        if ($request->has('title')) {
            $payload['title'] = $request->string('title')->toString();
        }

        if ($request->has('content')) {
            $content = (array) $request->input('content', []);
            $payload['content'] = $content;
            // Regenerate content_text alongside the JSONB write so
            // search doesn't drift from the stored document.
            $payload['content_text'] = $this->textExtractor->extract($content);
        }

        $doc->fill($payload)->save();

        return response()->json([
            'doc' => new DocResource($doc->refresh()),
        ]);
    }

    public function archive(Request $request, Doc $doc): JsonResponse
    {
        $this->authorize('archive', $doc);

        $doc->forceFill([
            'is_archived' => ! $doc->is_archived,
            'updated_by' => $request->user()->id,
        ])->save();

        return response()->json([
            'doc' => new DocResource($doc->refresh()),
        ]);
    }

    public function destroy(Doc $doc): JsonResponse
    {
        $this->authorize('delete', $doc);

        \Illuminate\Support\Facades\DB::transaction(function () use ($doc): void {
            // task_resource_links stores (resource_type, resource_id)
            // polymorphically with no FK, so a dropped doc would
            // otherwise leave orphan "locked" placeholders on tasks
            // that linked to it. Clean them up in the same
            // transaction as the doc delete — the spec calls for
            // either ON DELETE CASCADE (impossible with polymorphic
            // FKs) or explicit cleanup.
            \App\Models\Tasks\TaskResourceLink::query()
                ->where('resource_type', \App\Enums\TaskResourceLinkKind::Doc->value)
                ->where('resource_id', $doc->id)
                ->delete();

            $doc->delete();
        });

        return response()->json(status: 204);
    }
}
