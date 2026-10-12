<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GuestRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\V1\DeviceResource;
use App\Http\Resources\V1\UserResource;
use App\Models\Device;
use App\Models\User;
use App\Services\Api\MobileTokenService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    private const LOGIN_MAX_ATTEMPTS = 5;

    public function __construct(private readonly MobileTokenService $tokens) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        // Password hashing is handled by the model's "hashed" cast.
        $user = User::create($request->safe()->only(['name', 'email', 'password']));

        event(new Registered($user));

        return $this->signedIn($user, $request->validated('device'), 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $key = Str::transliterate(Str::lower($request->string('email')).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, self::LOGIN_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            throw new ApiException('too_many_attempts', 'Too many sign-in attempts. Try again later.', 429, ['retry_after' => $seconds], ['Retry-After' => (string) $seconds]);
        }

        $user = User::query()->where('email', $request->string('email')->toString())->first();

        // Always run one hash comparison so response time does not reveal whether the email exists.
        $hash = $user?->password ?: Hash::make(Str::random(16));
        $valid = Hash::check($request->string('password')->toString(), $hash) && $user !== null && $user->password !== null;

        if (! $valid) {
            RateLimiter::hit($key);

            throw new ApiException('invalid_credentials', 'These credentials do not match our records.', 401);
        }

        if ($user->isSuspended()) {
            throw ApiException::suspended($user->suspended_reason);
        }

        RateLimiter::clear($key);

        return $this->signedIn($user, $request->validated('device'));
    }

    /** Start a guest account (same as the web "try it without signing up"). */
    public function guest(GuestRequest $request): JsonResponse
    {
        if (! config('ironpulse.guest_mode')) {
            throw ApiException::notFound('Guest mode is not enabled.');
        }

        $guest = new User(['name' => 'Guest']);
        $guest->is_guest = true;
        $guest->save();

        return $this->signedIn($guest, $request->validated('device'), 201);
    }

    /** Rotate the caller's token (call before `refresh_after` passes; an expired token cannot be refreshed). */
    public function refresh(Request $request): JsonResponse
    {
        $result = $this->tokens->rotate($request->user(), $request->user()->currentAccessToken());

        return response()->json(['data' => ['token' => $result['token']]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        Device::query()->where('user_id', $request->user()->id)->where('personal_access_token_id', $token->getKey())
            ->get()->each(fn (Device $d) => $this->tokens->revokeDevice($d));
        $token->delete(); // also covers a token that has no device record

        return response()->json(['data' => ['ok' => true]]);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $this->tokens->revokeAll($request->user());

        return response()->json(['data' => ['ok' => true]]);
    }

    public function sessions(Request $request): AnonymousResourceCollection
    {
        $devices = $request->user()->devices()->whereNotNull('personal_access_token_id')->orderByDesc('last_seen_at')->get();

        return DeviceResource::collection($devices);
    }

    public function revokeSession(Request $request, int $device): JsonResponse
    {
        $record = $request->user()->devices()->whereKey($device)->first() ?? throw ApiException::notFound();

        $this->tokens->revokeDevice($record);

        return response()->json(['data' => ['ok' => true]]);
    }

    /** Always answers 200 so the endpoint cannot be used to discover which emails have accounts. */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:190']]);

        Password::sendResetLink(['email' => mb_strtolower($data['email'])]);

        return response()->json(['data' => ['message' => 'If that email has an account, a reset link is on its way.']]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:190'],
            'password' => ['required', 'string', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            ['email' => mb_strtolower($data['email']), 'password' => $data['password'], 'password_confirmation' => $data['password'], 'token' => $data['token']],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                // A reset signs the account out everywhere.
                $this->tokens->revokeAll($user);
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new ApiException('invalid_reset_token', 'This reset link is invalid or has expired.', 422);
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    /** @param  array<string, mixed>  $device */
    private function signedIn(User $user, array $device, int $status = 200): JsonResponse
    {
        $result = $this->tokens->issue($user, $device);
        $user->load('profile');

        return response()->json([
            'data' => [
                'token' => $result['token'],
                'device_id' => $result['device']->id,
                'user' => new UserResource($user),
            ],
        ], $status);
    }
}
