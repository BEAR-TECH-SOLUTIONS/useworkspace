<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Identity\PersonalProjectFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class RegisterController extends Controller
{
    public function __construct(private readonly PersonalProjectFactory $personalProjectFactory) {}

    public function __invoke(RegisterRequest $request): JsonResponse
    {
        try {
            $user = DB::transaction(function () use ($request): User {
                $user = User::create([
                    'name' => $request->string('name')->toString(),
                    'email' => $request->string('email')->toString(),
                    'password_hash' => Hash::make($request->string('password')->toString()),
                ]);

                // The personal org/project and default containers are bootstrapped
                // up-front even though the user hasn't picked a master password
                // yet. They own these resources regardless of whether they ever
                // finish the crypto setup; the gate on actually *using* them lives
                // in the EnsureMasterPasswordSet middleware.
                $this->personalProjectFactory->bootstrap($user);

                return $user;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Race-window guard: RegisterRequest::withValidator already
            // catches duplicates, but a concurrent signup between
            // validation and INSERT could still hit the unique index.
            // Re-emit the same generic message so the response is
            // indistinguishable from the validator-rejected case
            // (audit H4 enumeration oracle).
            throw ValidationException::withMessages([
                'email' => [__('Please use a valid, unused email address.')],
            ]);
        }

        $token = $user->createToken('default')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ], 201);
    }
}
