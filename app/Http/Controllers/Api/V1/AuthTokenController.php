<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IssueApiTokenRequest;
use App\Http\Resources\Api\V1\ApiTokenResource;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthTokenController extends Controller
{
    /**
     * Bcrypt hash of an unrelated password. Checking it when the email is
     * unknown keeps the response time close to a real password check.
     */
    private const UNKNOWN_USER_PASSWORD_HASH = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function store(IssueApiTokenRequest $request): ApiTokenResource
    {
        $user = User::query()->where('email', $request->string('email')->toString())->first();
        $password = $request->string('password')->toString();
        $hash = $user->password ?? self::UNKNOWN_USER_PASSWORD_HASH;

        if ($user === null || ! Hash::check($password, $hash)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $issued = $user->createToken(
            $request->validated('device_name') ?? 'api',
            ['compliance:audit', 'contracts:parse'],
        );

        return new ApiTokenResource([
            'token_type' => 'Bearer',
            'abilities' => $issued->accessToken->abilities,
            'token' => $issued->plainTextToken,
        ]);
    }
}
