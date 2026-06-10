<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\RefreshTokenController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\RotateMasterPasswordController;
use App\Http\Controllers\Api\V1\Auth\SetupMasterPasswordController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorController;
use App\Http\Controllers\Api\V1\BoardMemberController;
use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\BucketMemberController;
use App\Http\Controllers\Api\V1\CredentialByUrlController;
use App\Http\Controllers\Api\V1\DeferredAccessController;
use App\Http\Controllers\Api\V1\DocController;
use App\Http\Controllers\Api\V1\DocMemberController;
use App\Http\Controllers\Api\V1\CredentialController;
use App\Http\Controllers\Api\V1\ExpenseAnalyticsController;
use App\Http\Controllers\Api\V1\ExpenseBucketController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\ExpensePaymentController;
use App\Http\Controllers\Api\V1\LinkedTasksController;
use App\Http\Controllers\Api\V1\TaskResourceLinkController;
use App\Http\Controllers\Api\V1\MeAccessController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectDashboardController;
use App\Http\Controllers\Api\V1\ProjectSearchController;
use App\Http\Controllers\Api\V1\ServerAttestController;
use App\Http\Controllers\Api\V1\ProjectMemberController;
use App\Http\Controllers\Api\V1\PublicShareLinkController;
use App\Http\Controllers\Api\V1\ShareLinkController;
use App\Http\Controllers\Api\V1\ShareLinkNavigationController;
use App\Http\Controllers\Api\V1\TaskActivityController;
use App\Http\Controllers\Api\V1\TaskAssigneeController;
use App\Http\Controllers\Api\V1\TaskBoardController;
use App\Http\Controllers\Api\V1\TaskChecklistController;
use App\Http\Controllers\Api\V1\TaskColumnController;
use App\Http\Controllers\Api\V1\TaskCommentController;
use App\Http\Controllers\Api\V1\TaskItemController;
use App\Http\Controllers\Api\V1\TaskLabelController;
use App\Http\Controllers\Api\V1\UserPublicKeyController;
use App\Http\Controllers\Api\V1\VaultController;
use App\Http\Controllers\Api\V1\VaultKeyController;
use App\Http\Controllers\Api\V1\VaultMemberController;
use App\Http\Controllers\Api\V1\WaitlistController;
use App\Http\Controllers\Api\V1\MeWorkspaceInvitationController;
use App\Http\Controllers\Api\V1\WorkspaceBillingController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Controllers\Api\V1\WorkspaceInvitationController;
use App\Http\Controllers\Api\V1\WorkspaceMemberController;
use App\Models\Identity\Organisation;
use Illuminate\Support\Facades\Route;

// Route model binding — the URL says `{workspace}` (spec §2.1 API
// rename) but the backing model lives at App\Models\Identity\Organisation.
Route::bind('workspace', fn ($value) => Organisation::query()->findOrFail($value));

Route::prefix('v1')->group(function (): void {
    // Unauthenticated
    Route::post('/register', RegisterController::class)->middleware('throttle:register');
    Route::post('/login', LoginController::class)->middleware('throttle:login');
    // Login 2FA challenge — public, authenticated by challenge token.
    Route::post('/auth/2fa/challenge', TwoFactorChallengeController::class)->middleware('throttle:login');

    // Stripe webhook — unauthenticated; signature verification lives
    // in the controller once Stripe is wired. Stubbed 501 for now.
    Route::post('/billing/webhook', [WorkspaceBillingController::class, 'webhook']);

    // Anti-phishing identity probe — self-hosted installs only.
    // Returns a cloud-signed attestation envelope (proves the install
    // is licensed) plus the supplied nonce signed by the install's
    // per-host Ed25519 private key (proves the responding server holds
    // the matching private key, not just a replay of someone else's
    // attestation). Cloud-edition replies 503 `no_attestation` — that's
    // expected, the cloud's identity is anchored by its TLS certificate
    // instead. Throttled per-IP — see RateLimiter::for('server-attest').
    Route::post('/server/attest', [ServerAttestController::class, 'attest'])
        ->middleware('throttle:server-attest');

    // Public landing-page waitlist signup. Heavily throttled per IP;
    // honeypot + idempotent DB insert handle the rest. See
    // WaitlistController docblock for the spam-protection layers.
    Route::post('/waitlist', [WaitlistController::class, 'store'])
        ->middleware('throttle:waitlist');


    // Public share-link endpoints (CLAUDE §10 + Universal Share Links plan).
    // Both GET and POST get a per-IP + per-token throttle (audit M5) —
    // GET previously had none, which let an attacker walk valid
    // tokenHash space cheaply once they had one leaked token prefix.
    Route::middleware('share-link-headers')->group(function (): void {
        Route::get('/share-links/{tokenHash}', [PublicShareLinkController::class, 'show'])
            ->where('tokenHash', '[a-f0-9]{64}')
            ->middleware('throttle:share-show');
        Route::post('/share-links/{tokenHash}/unlock', [PublicShareLinkController::class, 'unlock'])
            ->where('tokenHash', '[a-f0-9]{64}')
            ->middleware('throttle:share-unlock');
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        // Always reachable while authed — even without a master password,
        // so the client can detect the setup-required state and submit it.
        Route::post('/logout', LogoutController::class);
        Route::get('/auth/me', [MeController::class, 'show']);
        Route::patch('/auth/me', [MeController::class, 'update']);

        // Active-session token rotation. Refresh only succeeds inside
        // the last third of the token's TTL (server enforces) so a
        // stolen bearer can't rotate itself indefinitely. See the
        // controller for the full window + 2FA contract.
        Route::post('/auth/token/refresh', RefreshTokenController::class)
            ->middleware('throttle:token-refresh');
        // Audit M16: per-endpoint throttle so `current_password`
        // can't be brute-forced silently by a stolen bearer trying
        // to change the password (which would also rotate sanctum
        // tokens). Limit is generous enough not to hit honest UI.
        Route::put('/auth/password', [PasswordController::class, 'update'])
            ->middleware('throttle:password-change');
        Route::post('/auth/master-password', SetupMasterPasswordController::class);

        // Rotate the master-password crypto bundle. Distinct from the
        // POST (one-time setup) — see RotateMasterPasswordController
        // for the security model. Same throttle as PUT /auth/password
        // because both are auth-sensitive write endpoints that take
        // a `current_password` field.
        Route::put('/auth/master-password', RotateMasterPasswordController::class)
            ->middleware('throttle:password-change');

        // Full access map for the current user — drives the client's
        // canRead/canWrite/canManage helpers. Includes Pattern B grants
        // (direct vault/board/bucket rows) that do not appear in
        // /projects/{project}/members.
        Route::get('/me/access', MeAccessController::class);

        // One-shot app-state hydrate: user + workspaces + projects
        // (with inline boards/vaults/buckets) + flattened access map.
        // Called once on login/reload to replace the 5+ boot fanout.
        Route::get('/me/bootstrap', BootstrapController::class);

        // Browser extension — cross-workspace credential lookup by domain.
        Route::get('/me/credentials/by-url', CredentialByUrlController::class);

        // Share-link navigation lookup. Hit by the desktop client right
        // after the `usework://s/{tokenHash}` deep-link opens the app:
        // exchanges the public token hash for the board_id / vault_id /
        // bucket_id + project_id the in-app router needs to land on the
        // right page. Distinct from the owner-side /share-links/{id}
        // (which is by-id + owner-only + returns the snapshot) and from
        // the public /share-links/{tokenHash} (which is anonymous +
        // returns the encrypted blob + counts as a recipient view).
        // Deliberately OUTSIDE `master-password.set` so the deep-link
        // works even when the master-password handshake hasn't run yet.
        Route::get('/share-links/by-hash/{tokenHash}', [ShareLinkNavigationController::class, 'showByHash'])
            ->where('tokenHash', '[a-f0-9]{64}');

        // Global notifications inbox. Every endpoint is scoped to the
        // authenticated user implicitly — no user id in the URL.
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::delete('/notifications', [NotificationController::class, 'destroyAll']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

        // Two-factor authentication (CLAUDE.md §7). Enrol / confirm / verify
        // / recover are all reachable by any authed user so the client can
        // drive the enrolment dance. `disable` requires a fresh 2FA proof
        // so a stolen bearer token cannot turn it off.
        Route::post('/auth/2fa/enroll', [TwoFactorController::class, 'enroll']);
        Route::post('/auth/2fa/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('/auth/2fa/verify', [TwoFactorController::class, 'verify']);
        Route::post('/auth/2fa/recover', [TwoFactorController::class, 'recover']);
        Route::delete('/auth/2fa', [TwoFactorController::class, 'disable'])->middleware('2fa');

        // User public-key lookup — safe to leave open. Returns `public_key:
        // null` for users who haven't finished the master-password handshake,
        // which the client already handles.
        Route::get('/users/by-email/{email}/public-key', UserPublicKeyController::class)
            // Audit L10: tight character class + length bound. Without
            // the length cap a long pathological string still routes,
            // which the controller would have to discard.
            ->where('email', '[A-Za-z0-9._%+\\-@]{3,255}')
            ->middleware('throttle:public-key-lookup');

        // Workspaces (the `organisations` table, renamed in the API
        // surface). Thin layer above projects: billing unit, member
        // directory, identity container. Commit 1 ships read/rename +
        // directory + member role/removal; invitations and billing
        // land in commits 2 and 4.
        Route::get('/workspaces', [WorkspaceController::class, 'index']);
        Route::post('/workspaces', [WorkspaceController::class, 'store']);
        Route::get('/workspaces/{workspace}', [WorkspaceController::class, 'show']);
        Route::patch('/workspaces/{workspace}', [WorkspaceController::class, 'update']);

        Route::get('/workspaces/{workspace}/resource-tree', [WorkspaceController::class, 'resourceTree']);
        Route::post('/workspaces/{workspace}/provision-user', [WorkspaceController::class, 'provisionUser']);

        // Deferred access grants — intent stashed by provisioning that
        // couldn't be applied yet (the new user has no public key).
        // Owners finalise each row once the user completes
        // POST /auth/master-password.
        Route::get('/workspaces/{workspace}/deferred-access', [DeferredAccessController::class, 'index']);
        Route::post('/workspaces/{workspace}/deferred-access/finalize-batch', [DeferredAccessController::class, 'finalizeBatch']);
        Route::post('/deferred-access/{deferredAccess}/finalize', [DeferredAccessController::class, 'finalize']);

        Route::get('/workspaces/{workspace}/members', [WorkspaceMemberController::class, 'index']);
        Route::get('/workspaces/{workspace}/members/{user}/access', [WorkspaceMemberController::class, 'access']);
        Route::patch('/workspaces/{workspace}/members/{user}', [WorkspaceMemberController::class, 'update']);
        Route::delete('/workspaces/{workspace}/members/{user}', [WorkspaceMemberController::class, 'destroy']);

        // Workspace invitations (admin side).
        Route::get('/workspaces/{workspace}/invitations', [WorkspaceInvitationController::class, 'index']);
        Route::post('/workspaces/{workspace}/invitations', [WorkspaceInvitationController::class, 'store']);
        Route::delete('/workspaces/{workspace}/invitations/{invitation}', [WorkspaceInvitationController::class, 'destroy']);

        // Invitee-side. Not scoped under a workspace — the user may not
        // be a member yet.
        Route::get('/me/workspace-invitations', [MeWorkspaceInvitationController::class, 'index']);
        Route::post('/me/workspace-invitations/{invitation}/accept', [MeWorkspaceInvitationController::class, 'accept']);
        Route::post('/me/workspace-invitations/{invitation}/decline', [MeWorkspaceInvitationController::class, 'decline']);

        // Billing (admin only). Stubbed 501 until Stripe lands —
        // shape is final so the client can build against the real
        // contract. See WorkspaceBillingController.
        Route::post('/workspaces/{workspace}/billing/checkout', [WorkspaceBillingController::class, 'checkout']);
        Route::post('/workspaces/{workspace}/billing/portal', [WorkspaceBillingController::class, 'portal']);
        Route::get('/workspaces/{workspace}/billing', [WorkspaceBillingController::class, 'summary']);
        Route::post('/workspaces/{workspace}/billing/cancel', [WorkspaceBillingController::class, 'cancel']);

        // Sandbox-only endpoints. Registered unconditionally so
        // OpenAPI stays stable; short-circuit to 404 when the active
        // driver isn't the sandbox. Enable with BILLING_DRIVER=sandbox.
        Route::post('/billing/sandbox/checkout/{session}', [WorkspaceBillingController::class, 'sandboxCheckoutComplete']);
        Route::post('/workspaces/{workspace}/billing/sandbox/cancel', [WorkspaceBillingController::class, 'sandboxCancel']);

        // Projects (boards + expenses work without a master password, so
        // project CRUD and membership are reachable without the crypto
        // bundle). Vault access is materialised via wrapped keys in
        // resource_keys, populated by the migrate-key / rotate-key flow
        // in Step B — project-create and member-invite do not carry any
        // key material directly.
        Route::get('/projects', [ProjectController::class, 'index']);
        Route::post('/projects', [ProjectController::class, 'store']);
        Route::get('/projects/{project}', [ProjectController::class, 'show']);
        Route::patch('/projects/{project}', [ProjectController::class, 'update']);
        Route::post('/projects/{project}/archive', [ProjectController::class, 'archive']);
        Route::post('/projects/{project}/unarchive', [ProjectController::class, 'unarchive']);
        // Destroying a project is the highest-stakes action in the whole
        // app — gated by the same 2FA freshness check §7 mandates.
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->middleware('2fa');
        // Hard-delete every child resource while keeping the project row
        // and its membership intact. Original-owner only (§ProjectPolicy).
        Route::delete('/projects/{project}/contents', [ProjectController::class, 'purgeContents'])->middleware('2fa');

        // Project-wide search
        Route::get('/projects/{project}/search', ProjectSearchController::class);
        Route::get('/projects/{project}/dashboard', ProjectDashboardController::class);
        Route::get('/projects/{project}/resources', [ProjectController::class, 'resources']);

        // Docs — Notion-style rich-text documents. Same access model
        // as boards/buckets (no crypto) with Pattern B per-doc grants.
        Route::get('/projects/{project}/docs', [DocController::class, 'index']);
        Route::post('/projects/{project}/docs', [DocController::class, 'store']);
        Route::get('/docs/{doc}', [DocController::class, 'show']);
        Route::patch('/docs/{doc}', [DocController::class, 'update']);
        Route::post('/docs/{doc}/archive', [DocController::class, 'archive']);
        Route::delete('/docs/{doc}', [DocController::class, 'destroy']);

        Route::get('/docs/{doc}/members', [DocMemberController::class, 'index']);
        Route::post('/docs/{doc}/members', [DocMemberController::class, 'store']);
        Route::patch('/docs/{doc}/members/{user}', [DocMemberController::class, 'update']);
        Route::delete('/docs/{doc}/members/{user}', [DocMemberController::class, 'destroy']);

        // Project members
        Route::get('/projects/{project}/members', [ProjectMemberController::class, 'index']);
        Route::post('/projects/{project}/members', [ProjectMemberController::class, 'store']);
        Route::patch('/projects/{project}/members/{user}', [ProjectMemberController::class, 'update']);
        // Unified single-transaction access mutation — replaces the
        // legacy POST/DELETE/PATCH dance. See Members & Permissions §2.
        Route::put('/projects/{project}/members/{user}/access', [ProjectMemberController::class, 'access']);
        Route::delete('/projects/{project}/members/{user}', [ProjectMemberController::class, 'destroy']);

        // Task boards
        Route::get('/projects/{project}/task-boards', [TaskBoardController::class, 'index']);
        Route::post('/projects/{project}/task-boards', [TaskBoardController::class, 'store']);
        Route::get('/task-boards/{taskBoard}', [TaskBoardController::class, 'show']);
        Route::patch('/task-boards/{taskBoard}', [TaskBoardController::class, 'update']);
        Route::post('/task-boards/{taskBoard}/archive', [TaskBoardController::class, 'archive']);
        Route::delete('/task-boards/{taskBoard}', [TaskBoardController::class, 'destroy']);
        Route::get('/task-boards/{taskBoard}/activities', [TaskActivityController::class, 'forBoard']);
        Route::get('/task-boards/{taskBoard}/archived-tasks', [TaskBoardController::class, 'archivedTasks']);

        // Per-board membership (Pattern B — users with only a direct
        // board grant, no project-level access). Managed separately from
        // /projects/{project}/members, which intentionally lists only
        // project-level rows.
        Route::get('/task-boards/{taskBoard}/members', [BoardMemberController::class, 'index']);
        Route::post('/task-boards/{taskBoard}/members', [BoardMemberController::class, 'store']);
        Route::patch('/task-boards/{taskBoard}/members/{user}', [BoardMemberController::class, 'update']);
        Route::delete('/task-boards/{taskBoard}/members/{user}', [BoardMemberController::class, 'destroy']);

        // Task columns
        Route::post('/task-boards/{taskBoard}/columns', [TaskColumnController::class, 'store']);
        Route::post('/task-boards/{taskBoard}/columns/reorder', [TaskColumnController::class, 'reorder']);
        Route::patch('/task-columns/{taskColumn}', [TaskColumnController::class, 'update']);
        Route::delete('/task-columns/{taskColumn}', [TaskColumnController::class, 'destroy']);

        // Task items
        Route::post('/task-boards/{taskBoard}/task-items', [TaskItemController::class, 'store']);
        Route::get('/task-items/{taskItem}', [TaskItemController::class, 'show']);
        Route::patch('/task-items/{taskItem}', [TaskItemController::class, 'update']);
        Route::delete('/task-items/{taskItem}', [TaskItemController::class, 'destroy']);
        Route::post('/task-items/{taskItem}/archive', [TaskItemController::class, 'archive']);
        Route::post('/task-items/{taskItem}/move', [TaskItemController::class, 'move']);
        Route::get('/task-items/{taskItem}/activities', [TaskActivityController::class, 'forTask']);

        // Task resource attachments — lightweight, metadata-only
        // references to credentials / expense buckets / expenses in
        // the same project. Read-time gated per entry.
        Route::get('/task-items/{taskItem}/resources', [TaskResourceLinkController::class, 'index']);
        Route::post('/task-items/{taskItem}/resources', [TaskResourceLinkController::class, 'store']);
        Route::delete('/task-items/{taskItem}/resources/{link}', [TaskResourceLinkController::class, 'destroy']);

        // Task checklists
        Route::post('/task-items/{taskItem}/checklists', [TaskChecklistController::class, 'store']);
        Route::patch('/task-checklists/{taskChecklist}', [TaskChecklistController::class, 'update']);
        Route::delete('/task-checklists/{taskChecklist}', [TaskChecklistController::class, 'destroy']);

        // Task comments
        Route::get('/task-items/{taskItem}/comments', [TaskCommentController::class, 'index']);
        Route::post('/task-items/{taskItem}/comments', [TaskCommentController::class, 'store']);
        Route::patch('/task-comments/{taskComment}', [TaskCommentController::class, 'update']);
        Route::delete('/task-comments/{taskComment}', [TaskCommentController::class, 'destroy']);

        // Task labels (project-scope CRUD + task attachment)
        Route::get('/projects/{project}/task-labels', [TaskLabelController::class, 'index']);
        Route::post('/projects/{project}/task-labels', [TaskLabelController::class, 'store']);
        Route::patch('/task-labels/{taskLabel}', [TaskLabelController::class, 'update']);
        Route::delete('/task-labels/{taskLabel}', [TaskLabelController::class, 'destroy']);
        Route::put('/task-items/{taskItem}/labels/{taskLabel}', [TaskLabelController::class, 'attach']);
        Route::delete('/task-items/{taskItem}/labels/{taskLabel}', [TaskLabelController::class, 'detach']);

        // Task assignees
        Route::put('/task-items/{taskItem}/assignees/{user}', [TaskAssigneeController::class, 'store']);
        Route::delete('/task-items/{taskItem}/assignees/{user}', [TaskAssigneeController::class, 'destroy']);

        // Expense buckets
        Route::get('/projects/{project}/expense-buckets', [ExpenseBucketController::class, 'index']);
        Route::post('/projects/{project}/expense-buckets', [ExpenseBucketController::class, 'store']);
        Route::get('/expense-buckets/{expenseBucket}', [ExpenseBucketController::class, 'show']);
        Route::patch('/expense-buckets/{expenseBucket}', [ExpenseBucketController::class, 'update']);
        Route::post('/expense-buckets/{expenseBucket}/archive', [ExpenseBucketController::class, 'archive']);
        Route::delete('/expense-buckets/{expenseBucket}', [ExpenseBucketController::class, 'destroy']);

        // Per-bucket membership (Pattern B).
        Route::get('/expense-buckets/{expenseBucket}/members', [BucketMemberController::class, 'index']);
        Route::post('/expense-buckets/{expenseBucket}/members', [BucketMemberController::class, 'store']);
        Route::patch('/expense-buckets/{expenseBucket}/members/{user}', [BucketMemberController::class, 'update']);
        Route::delete('/expense-buckets/{expenseBucket}/members/{user}', [BucketMemberController::class, 'destroy']);

        // Expenses resources
        Route::get('/projects/{project}/expenses', [ExpenseController::class, 'index']);
        Route::get('/projects/{project}/expenses/upcoming', [ExpenseController::class, 'upcoming']);
        Route::get('/projects/{project}/expenses/summary', [ExpenseAnalyticsController::class, 'summary']);
        Route::get('/projects/{project}/expenses/trend', [ExpenseAnalyticsController::class, 'trend']);
        Route::get('/projects/{project}/expenses/forecast', [ExpenseAnalyticsController::class, 'forecast']);
        Route::get('/projects/{project}/expenses/history', [ExpenseAnalyticsController::class, 'history']);
        Route::post('/projects/{project}/expenses', [ExpenseController::class, 'store']);
        Route::get('/expenses/{expense}', [ExpenseController::class, 'show']);
        Route::patch('/expenses/{expense}', [ExpenseController::class, 'update']);
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy']);

        // Expense payments.
        Route::post('/expenses/{expense}/pay', [ExpensePaymentController::class, 'pay']);
        Route::get('/expenses/{expense}/payments', [ExpensePaymentController::class, 'index']);
        Route::delete('/expenses/{expense}/payments/{payment}', [ExpensePaymentController::class, 'destroy']);

        // Reverse lookups — "what tasks reference this resource?".
        // Task Resource Attachments spec §3.4; gated by view-access
        // to the source resource.
        Route::get('/expense-buckets/{expenseBucket}/linked-tasks', [LinkedTasksController::class, 'forExpenseBucket']);
        Route::get('/expenses/{expense}/linked-tasks', [LinkedTasksController::class, 'forExpense']);

        // ── Vault module ──────────────────────────────────────────────────
        // The password manager is the one module that genuinely can't work
        // without a master password — credentials are encrypted with a
        // project key wrapped in the user's RSA public key, and neither
        // exists until the crypto bundle is uploaded. Everything in this
        // group returns 409 `master_password_required` until the user
        // finishes the handshake.
        Route::middleware('master-password.set')->group(function (): void {
            // Vaults
            Route::get('/projects/{project}/vaults', [VaultController::class, 'index']);
            Route::post('/projects/{project}/vaults', [VaultController::class, 'store']);
            Route::get('/vaults/{vault}', [VaultController::class, 'show']);
            Route::patch('/vaults/{vault}', [VaultController::class, 'update']);
            Route::post('/vaults/{vault}/archive', [VaultController::class, 'archive']);
            Route::delete('/vaults/{vault}', [VaultController::class, 'destroy']);

            // Vault key lifecycle (CLAUDE.md §6.5). Owner-only via the
            // `share` ability; 2FA is deliberately NOT required — the
            // client orchestrates the flow and the server only stores
            // ciphertext, so a stolen bearer token that hits these
            // endpoints cannot leak any plaintext on its own.
            // migrate-key runs exactly once per vault, rotate can run any
            // number of times after that.
            Route::post('/vaults/{vault}/migrate-key', [VaultKeyController::class, 'migrate']);
            Route::post('/vaults/{vault}/rotate-key', [VaultKeyController::class, 'rotate']);
            Route::post('/vaults/{vault}/wrap-key', [VaultKeyController::class, 'wrapKey']);

            // Per-vault membership (Pattern B). Unlike board/bucket, a
            // vault grant carries a wrapped key — the client MUST send
            // `encrypted_key` on store() and the server persists it into
            // `resource_keys` at the vault's current key_version.
            Route::get('/vaults/{vault}/members', [VaultMemberController::class, 'index']);
            Route::post('/vaults/{vault}/members', [VaultMemberController::class, 'store']);
            Route::patch('/vaults/{vault}/members/{user}', [VaultMemberController::class, 'update']);
            Route::delete('/vaults/{vault}/members/{user}', [VaultMemberController::class, 'destroy']);

            // Credentials
            Route::get('/projects/{project}/credentials', [CredentialController::class, 'index']);
            Route::post('/projects/{project}/credentials', [CredentialController::class, 'store'])
                ->middleware('throttle:vault');
            Route::get('/credentials/{credential}', [CredentialController::class, 'show']);
            Route::patch('/credentials/{credential}', [CredentialController::class, 'update'])
                ->middleware('throttle:vault');
            Route::delete('/credentials/{credential}', [CredentialController::class, 'destroy']);
            Route::get('/credentials/{credential}/history', [CredentialController::class, 'history']);
            Route::get('/credentials/{credential}/linked-tasks', [LinkedTasksController::class, 'forCredential']);

            // Universal share links (owner endpoints) — polymorphic over
            // board/task/credential/doc/expense. Plan §13.
            Route::post('/share-links', [ShareLinkController::class, 'store']);
            Route::get('/me/share-links', [ShareLinkController::class, 'mine']);
            Route::get('/share-links/{shareLink}', [ShareLinkController::class, 'show']);
            Route::delete('/share-links/{shareLink}', [ShareLinkController::class, 'destroy']);
            Route::get('/share-links/{shareLink}/views', [ShareLinkController::class, 'views']);
        });
    });
});
