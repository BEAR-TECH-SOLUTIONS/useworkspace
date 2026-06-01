<?php

namespace App\Providers;

use App\Models\Docs\Doc;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Identity\Organisation;
use App\Models\Project\Project;
use App\Models\Tasks\TaskBoard;
use App\Models\Vault\Credential;
use App\Models\Vault\ShareLink;
use App\Models\Vault\Vault;
use App\Policies\CredentialPolicy;
use App\Policies\DocPolicy;
use App\Policies\ExpenseBucketPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ShareLinkPolicy;
use App\Policies\TaskBoardPolicy;
use App\Policies\VaultPolicy;
use App\Policies\WorkspacePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Project::class => ProjectPolicy::class,
        TaskBoard::class => TaskBoardPolicy::class,
        Vault::class => VaultPolicy::class,
        Credential::class => CredentialPolicy::class,
        ExpenseBucket::class => ExpenseBucketPolicy::class,
        Expense::class => ExpensePolicy::class,
        Organisation::class => WorkspacePolicy::class,
        Doc::class => DocPolicy::class,
        ShareLink::class => ShareLinkPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
