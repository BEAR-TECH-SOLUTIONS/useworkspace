<?php

namespace App\Http\Requests\Tasks;

use App\Models\Tasks\TaskComment;
use App\Models\Tasks\TaskItem;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreTaskCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:10000'],
            'parent_id' => ['nullable', 'integer', 'exists:task_comments,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Parent comment must live on the same task (audit H16).
            // Without this, a user with access to task A can reply to
            // a comment on task B (which they may not see) — reply-
            // chain UIs walking parent pointers leak the parent body.
            $parentId = $this->input('parent_id');
            $task = $this->route('taskItem');
            if ($parentId === null || ! $task instanceof TaskItem) {
                return;
            }

            $parent = TaskComment::query()->find((int) $parentId);
            if ($parent === null) {
                return; // `exists` rule will handle the missing case.
            }

            if ((int) $parent->task_item_id !== (int) $task->id) {
                $validator->errors()->add(
                    'parent_id',
                    'Parent comment must belong to the same task.',
                );
            }
        });
    }
}
