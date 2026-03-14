<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use OpenTelemetry\API\Trace\TracerInterface;
use Prometheus\CollectorRegistry;

class AuthController extends Controller
{
    public function __construct(
        private readonly CollectorRegistry $registry,
        private readonly TracerInterface $tracer,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
        ]);

        $token = $user->createToken('api')->plainTextToken;

        try {
            $this->registry->getOrRegisterCounter(
                config('observability.metrics.namespace', ''),
                'app_user_registrations_total',
                'Total number of successful user registrations',
                []
            )->inc();
        } catch (\Throwable) {
            // Never let metrics recording crash the application
        }

        // OTel named span — Requirement 1.5
        try {
            $span = $this->tracer->spanBuilder('auth.register')
                ->setAttribute('user.id', $user->id)
                ->setAttribute('http.status_code', 201)
                ->startSpan();
            $span->end();
        } catch (\Throwable) {
            // Never let tracing crash the application
        }

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'token' => $token,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::attempt(['email' => $request->email, 'password' => $request->password])) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        /** @var User $user */
        $user = Auth::user();
        $token = $user->createToken('api')->plainTextToken;

        try {
            $this->registry->getOrRegisterCounter(
                config('observability.metrics.namespace', ''),
                'app_user_logins_total',
                'Total number of successful user logins',
                []
            )->inc();
        } catch (\Throwable) {
            // Never let metrics recording crash the application
        }

        // OTel named span — Requirement 2.4
        try {
            $span = $this->tracer->spanBuilder('auth.login')
                ->setAttribute('http.status_code', 200)
                ->startSpan();
            $span->end();
        } catch (\Throwable) {
            // Never let tracing crash the application
        }

        return response()->json(['token' => $token], 200);
    }
}
