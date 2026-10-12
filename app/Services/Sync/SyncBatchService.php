<?php

namespace App\Services\Sync;

use App\Enums\Meal;
use App\Exceptions\ApiException;
use App\Http\Requests\Api\V1\LogSetRequest;
use App\Http\Requests\Api\V1\SessionStateRequest;
use App\Http\Requests\StoreFoodLogRequest;
use App\Http\Requests\StoreMeasurementRequest;
use App\Http\Requests\StorePostureSessionRequest;
use App\Models\Food;
use App\Models\FoodLog;
use App\Models\Measurement;
use App\Models\SessionSet;
use App\Models\SyncOperation;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\DailyLogService;
use App\Services\FoodLogService;
use App\Services\MeasurementService;
use App\Services\Membership\EntitlementService;
use App\Services\PostureSessionService;
use App\Services\WaterService;
use App\Services\WorkoutSessionService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Applies the operations a phone queued while it had no connection.
 *
 * Contract (docs/mobile/openapi.yaml, POST /sync/batch):
 *  - every operation has a client-generated `op_id`; an op already applied is answered `duplicate` and never runs twice
 *  - append-only records (sets, food entries, water) simply apply
 *  - state-like records (habits, measurements, set corrections) compare the client's timestamp with the server row:
 *    if the server copy is NEWER the op is a `conflict` and the server copy is returned, so stale local data never
 *    silently overwrites newer data. The member can resubmit with a fresh `client_created_at` to keep their version
 *  - a validation or permission problem is `rejected` (final); an unexpected failure stops the batch so ordering is kept
 */
class SyncBatchService
{
    public const MAX_OPERATIONS = 100;

    public function __construct(
        private readonly WorkoutSessionService $sessions,
        private readonly FoodLogService $foodLogs,
        private readonly WaterService $water,
        private readonly DailyLogService $daily,
        private readonly MeasurementService $measurements,
        private readonly PostureSessionService $posture,
        private readonly EntitlementService $entitlements,
    ) {}

    /** The membership feature each kind of operation needs: a queued operation is never a way around a paywall. */
    private const REQUIRES = [
        'workout.' => 'workouts',
        'food.' => 'nutrition',
        'water.' => 'nutrition',
        'posture.' => 'posture_coach',
    ];

    /**
     * @param  list<array{op_id: string, type: string, payload: array<string, mixed>, client_created_at?: ?string}>  $operations
     * @return list<array<string, mixed>>
     */
    public function run(User $user, array $operations): array
    {
        $user->loadMissing('profile');
        $results = [];
        $halted = false;

        foreach ($operations as $op) {
            if ($halted) {
                $results[] = ['op_id' => $op['op_id'], 'status' => 'not_processed'];

                continue;
            }

            $previous = SyncOperation::query()->where('user_id', $user->id)->where('op_id', $op['op_id'])->first();
            if ($previous) {
                $results[] = ['op_id' => $op['op_id'], 'status' => 'duplicate', 'original_status' => $previous->status] + ($previous->result ?? []);

                continue;
            }

            try {
                $outcome = DB::transaction(function () use ($user, $op) {
                    $clientAt = isset($op['client_created_at']) ? CarbonImmutable::parse($op['client_created_at']) : null;
                    $outcome = $this->apply($user, $op['type'], $op['payload'] ?? [], $clientAt);
                    SyncOperation::create(['user_id' => $user->id, 'op_id' => $op['op_id'], 'type' => $op['type'], 'status' => $outcome['status'], 'result' => $outcome['result'] ?? null, 'client_created_at' => $clientAt]);

                    return $outcome;
                });
            } catch (ValidationException $e) {
                $outcome = $this->record($user, $op, 'rejected', ['error' => ['code' => 'validation_failed', 'message' => 'The operation is invalid.', 'details' => $e->errors()]]);
            } catch (ApiException|HttpExceptionInterface $e) {
                $status = $e instanceof ApiException ? $e->status : $e->getStatusCode();
                if ($status >= 500) {
                    $halted = true;
                    $results[] = ['op_id' => $op['op_id'], 'status' => 'error', 'retryable' => true];

                    continue;
                }
                $outcome = $this->record($user, $op, 'rejected', ['error' => [
                    'code' => $e instanceof ApiException ? $e->errorCode : ($status === 409 ? 'session_closed' : ($status === 404 ? 'not_found' : 'rejected')),
                    'message' => $e->getMessage() ?: 'The operation was rejected.',
                ]]);
            } catch (Throwable $e) {
                report($e);
                $halted = true;
                $results[] = ['op_id' => $op['op_id'], 'status' => 'error', 'retryable' => true];

                continue;
            }

            $results[] = ['op_id' => $op['op_id'], 'status' => $outcome['status']] + ($outcome['result'] ?? []);
        }

        return $results;
    }

    /** @param  array<string, mixed>  $result */
    private function record(User $user, array $op, string $status, array $result): array
    {
        SyncOperation::create(['user_id' => $user->id, 'op_id' => $op['op_id'], 'type' => $op['type'], 'status' => $status, 'result' => $result, 'client_created_at' => isset($op['client_created_at']) ? CarbonImmutable::parse($op['client_created_at']) : null]);

        return ['status' => $status, 'result' => $result];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array{status: string, result?: array<string, mixed>}
     */
    private function apply(User $user, string $type, array $p, ?CarbonInterface $clientAt): array
    {
        foreach (self::REQUIRES as $prefix => $entitlement) {
            if (str_starts_with($type, $prefix)) {
                $this->entitlements->authorize($user, $entitlement);
            }
        }

        return match ($type) {
            'workout.set.log' => $this->logSet($user, $p, $clientAt),
            'workout.set.delete' => $this->deleteSet($user, $p),
            'workout.exercise.skip' => $this->skipExercise($user, $p),
            'workout.state.save' => $this->saveState($user, $p),
            'workout.finish' => $this->finish($user, $p),
            'workout.abandon' => $this->abandon($user, $p),
            'food.log.create' => $this->createFoodLog($user, $p),
            'food.log.delete' => $this->deleteFoodLog($user, $p),
            'water.add' => $this->addWater($user, $p),
            'water.undo' => $this->undoWater($user, $p),
            'habit.creatine' => $this->habit($user, $p, $clientAt, fn (CarbonInterface $d) => $this->daily->setCreatine($user, $d, (bool) $p['taken']), ['taken' => ['required', 'boolean']]),
            'habit.sleep' => $this->habit($user, $p, $clientAt, fn (CarbonInterface $d) => $this->daily->logSleep($user, $d, $p), ['sleep_hours' => ['required', 'numeric', 'min:0', 'max:16'], 'sleep_quality' => ['nullable', 'integer', 'between:1,5'], 'bedtime' => ['nullable', 'date_format:H:i'], 'wake_time' => ['nullable', 'date_format:H:i']]),
            'measurement.save' => $this->saveMeasurement($user, $p, $clientAt),
            'posture.session.save' => $this->savePosture($user, $p),
            default => throw new ApiException('unknown_operation', "Unknown operation type '{$type}'.", 422),
        };
    }

    // ------------------------------------------------------------------ workouts

    /** @param  array<string, mixed>  $p */
    private function logSet(User $user, array $p, ?CarbonInterface $clientAt): array
    {
        $session = $this->session($user, $p);
        $req = $this->validate(LogSetRequest::class, $p, $user);
        $data = $req->validated();

        $row = $session->exercises()->whereKey($data['session_exercise_id'])->first() ?? throw ApiException::notFound('Exercise not found in this workout.');

        // A correction of an existing set loses to a newer change made elsewhere (for example on the website).
        $existing = SessionSet::query()->where(['session_exercise_id' => $row->id, 'set_number' => $data['set_number'], 'is_warmup' => (bool) ($data['is_warmup'] ?? false)])->first();
        if ($existing && $clientAt && $existing->updated_at && $existing->updated_at->gt($clientAt) && ! $this->sameSet($existing, $data)) {
            return ['status' => 'conflict', 'result' => ['server' => ['set_number' => $existing->set_number, 'weight_kg' => $existing->weight_kg, 'reps' => $existing->reps, 'duration_seconds' => $existing->duration_seconds, 'updated_at' => $existing->updated_at->toIso8601String()]]];
        }

        $result = $this->sessions->logSet($user, $session, $data);

        return ['status' => 'applied', 'result' => ['set_id' => $result['set']->id, 'is_pr' => $result['prs'] !== [], 'xp' => $result['xp'], 'totals' => $result['totals']]];
    }

    /** @param  array<string, mixed>  $data */
    private function sameSet(SessionSet $set, array $data): bool
    {
        return (float) $set->weight_kg === (float) ($data['weight_kg'] ?? 0) && (int) $set->reps === (int) ($data['reps'] ?? 0) && (int) $set->duration_seconds === (int) ($data['duration_seconds'] ?? 0);
    }

    /** @param  array<string, mixed>  $p */
    private function deleteSet(User $user, array $p): array
    {
        $session = $this->session($user, $p);
        $set = SessionSet::query()->where('workout_session_id', $session->id)->whereKey((int) ($p['set_id'] ?? 0))->first();
        if ($set) {
            $this->sessions->deleteSet($user, $session, $set);
        }

        return ['status' => 'applied']; // already gone is the state the member asked for
    }

    /** @param  array<string, mixed>  $p */
    private function skipExercise(User $user, array $p): array
    {
        $session = $this->session($user, $p);
        $row = $session->exercises()->whereKey((int) ($p['session_exercise_id'] ?? 0))->first() ?? throw ApiException::notFound('Exercise not found in this workout.');
        if ($row->status !== 'skipped') {
            $this->sessions->skipExercise($session, $row);
        }

        return ['status' => 'applied'];
    }

    /** @param  array<string, mixed>  $p */
    private function saveState(User $user, array $p): array
    {
        $session = $this->session($user, $p);
        $incoming = $this->validate(SessionStateRequest::class, $p, $user)->validated('state');
        $stored = $session->autosave_state['client'] ?? null;

        if (isset($stored['saved_at'], $incoming['saved_at']) && strtotime($incoming['saved_at']) < strtotime($stored['saved_at'])) {
            return ['status' => 'applied', 'result' => ['applied' => false]]; // a newer position is already stored
        }
        $this->sessions->saveClientState($session, $incoming);

        return ['status' => 'applied', 'result' => ['applied' => true]];
    }

    /** @param  array<string, mixed>  $p */
    private function finish(User $user, array $p): array
    {
        $session = $this->session($user, $p);
        validator($p, ['active_seconds' => ['nullable', 'integer', 'between:0,21600']])->validate();

        $this->sessions->finish($user, $session, $p['active_seconds'] ?? null);

        return ['status' => 'applied', 'result' => ['session_id' => $session->id]];
    }

    /** @param  array<string, mixed>  $p */
    private function abandon(User $user, array $p): array
    {
        $session = $this->session($user, $p);
        if ($session->isOpen()) {
            $this->sessions->abandon($session);
        }

        return ['status' => 'applied'];
    }

    // ------------------------------------------------------------------ nutrition and habits

    /** @param  array<string, mixed>  $p */
    private function createFoodLog(User $user, array $p): array
    {
        $req = $this->validate(StoreFoodLogRequest::class, $p, $user);
        $food = Food::query()->visibleTo($user)->with('servings')->find($req->integer('food_id')) ?? throw ApiException::notFound('Food not found.');

        $log = $this->foodLogs->add($user, $food, $req->dateInput('logged_on'), Meal::from($req->input('meal')), (float) $req->input('quantity'), $req->input('unit'), $req->input('serving_id'));

        return ['status' => 'applied', 'result' => ['log_id' => $log->id, 'grams' => (float) $log->grams, 'kcal' => (float) $log->kcal, 'protein_g' => (float) $log->protein_g, 'carbs_g' => (float) $log->carbs_g, 'fat_g' => (float) $log->fat_g]];
    }

    /** @param  array<string, mixed>  $p */
    private function deleteFoodLog(User $user, array $p): array
    {
        FoodLog::query()->where('user_id', $user->id)->whereKey((int) ($p['log_id'] ?? 0))->first()?->delete();

        return ['status' => 'applied'];
    }

    /** @param  array<string, mixed>  $p */
    private function addWater(User $user, array $p): array
    {
        validator($p, ['amount_ml' => ['required', 'integer', 'min:10', 'max:'.WaterService::MAX_SINGLE_ML], 'date' => ['nullable', 'date_format:Y-m-d']])->validate();
        $state = $this->water->add($user, $this->habitDate($user, $p), (int) $p['amount_ml']);

        return ['status' => 'applied', 'result' => ['total_ml' => $state['total_ml'], 'xp' => $state['xp']]];
    }

    /** @param  array<string, mixed>  $p */
    private function undoWater(User $user, array $p): array
    {
        $state = $this->water->undoLast($user, $this->habitDate($user, $p));

        return ['status' => 'applied', 'result' => ['total_ml' => $state['total_ml']]];
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $rules
     */
    private function habit(User $user, array $p, ?CarbonInterface $clientAt, \Closure $apply, array $rules): array
    {
        validator($p, $rules + ['date' => ['nullable', 'date_format:Y-m-d']])->validate();
        $date = $this->habitDate($user, $p);

        $existing = $this->daily->forDate($user, $date);
        if ($existing && $clientAt && $existing->updated_at && $existing->updated_at->gt($clientAt)) {
            return ['status' => 'conflict', 'result' => ['server' => ['date' => $date->toDateString(), 'creatine_taken' => (bool) $existing->creatine_taken, 'sleep_hours' => $existing->sleep_hours, 'updated_at' => $existing->updated_at->toIso8601String()]]];
        }
        $apply($date);

        return ['status' => 'applied'];
    }

    /** @param  array<string, mixed>  $p */
    private function saveMeasurement(User $user, array $p, ?CarbonInterface $clientAt): array
    {
        $req = $this->validate(StoreMeasurementRequest::class, $p, $user);
        $data = $req->measurementData();

        $existing = Measurement::where('user_id', $user->id)->whereDate('measured_on', $req->input('measured_on'))->first();
        if ($existing && $clientAt && $existing->updated_at && $existing->updated_at->gt($clientAt)) {
            $differs = collect($data)->filter(fn ($v, $k) => $v !== null && $k !== 'notes' && (float) $existing->{$k} !== (float) $v)->isNotEmpty();
            if ($differs) {
                return ['status' => 'conflict', 'result' => ['server' => ['measured_on' => $existing->measured_on->toDateString(), 'weight_kg' => $existing->weight_kg, 'body_fat_pct' => $existing->body_fat_pct, 'updated_at' => $existing->updated_at->toIso8601String()]]];
            }
        }
        $saved = $this->measurements->save($user, $req->input('measured_on'), $data);

        return ['status' => 'applied', 'result' => ['measurement_id' => $saved['measurement']->id]];
    }

    /** @param  array<string, mixed>  $p */
    private function savePosture(User $user, array $p): array
    {
        $req = $this->validate(StorePostureSessionRequest::class, $p, $user);
        $result = $this->posture->record($user, $req->validated());

        return ['status' => 'applied', 'result' => ['posture_session_id' => $result['session']->id, 'created' => $result['created'], 'xp' => $result['xp']['total'] ?? 0]];
    }

    // ------------------------------------------------------------------ helpers

    /** @param  array<string, mixed>  $p */
    private function session(User $user, array $p): WorkoutSession
    {
        return WorkoutSession::query()->where('user_id', $user->id)->whereKey((int) ($p['session_id'] ?? 0))->first() ?? throw ApiException::notFound('Workout not found.');
    }

    /** @param  array<string, mixed>  $p */
    private function habitDate(User $user, array $p): CarbonImmutable
    {
        $today = $user->localToday();
        if (empty($p['date'])) {
            return $today;
        }
        $date = CarbonImmutable::createFromFormat('Y-m-d', $p['date'], $user->timezone())->startOfDay();
        if ($date->gt($today) || $date->lt($today->subDays(7))) {
            throw ValidationException::withMessages(['date' => 'You can only edit the last 7 days.']);
        }

        return $date;
    }

    /**
     * Run one of the website / API form requests against an offline payload, so queued operations obey exactly
     * the rules online requests do.
     *
     * @template T of FormRequest
     *
     * @param  class-string<T>  $class
     * @param  array<string, mixed>  $payload
     * @return T
     */
    private function validate(string $class, array $payload, User $user): FormRequest
    {
        $request = $class::create('/api/v1/sync', 'POST', $payload);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->setUserResolver(fn () => $user);
        $request->validateResolved();

        return $request;
    }
}
