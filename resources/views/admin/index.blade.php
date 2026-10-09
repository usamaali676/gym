<x-admin.shell title="Admin" heading="Control room" lead="Edit the plan, exercises, posture rules and foods, and look after accounts. Every change is written to the application log with your user id.">
    <dl class="grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach ([
            ['Members', $stats['users'], 'Registered accounts'],
            ['Guests', $stats['guests'], 'Tried the app without signing up'],
            ['Active (7 days)', $stats['active'], 'Seen in the last week'],
            ['Admins', $stats['admins'], 'Can open this panel'],
            ['Workouts (7 days)', $stats['workouts'], 'Completed sessions'],
            ['Posture sets (7 days)', $stats['posture'], 'Saved by the lab'],
            ['Exercises', $stats['exercises'], 'In the library'],
            ['Foods', $stats['foods'], $stats['custom_foods'].' private custom foods'],
        ] as [$label, $value, $hint])
            <div class="glass p-4"><dt class="text-xs font-semibold uppercase tracking-wider text-muted">{{ $label }}</dt><dd class="num mt-1 text-2xl">{{ number_format($value) }}</dd><dd class="text-xs text-muted">{{ $hint }}</dd></div>
        @endforeach
    </dl>

    <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="glass p-5" aria-labelledby="health-title">
            <h2 id="health-title" class="text-sm font-bold uppercase">System checks</h2>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ([
                    ['Pose model and WebAssembly runtime', $health['mediapipe'], $health['mediapipe'] ? 'Installed under public/vendor/mediapipe.' : 'Missing. Run npm run vendor:mediapipe or the Posture Lab cannot start the camera.'],
                    ['Mail', $health['mail'] !== 'log' && $health['mail'] !== 'array', $health['mail'] === 'log' ? 'MAIL_MAILER=log: digests are written to the log, not sent.' : 'Using the '.$health['mail'].' mailer.'],
                    ['AI coach', ! $health['coach_enabled'] || $health['coach'], ! $health['coach_enabled'] ? 'Switched off (COACH_ENABLED=false).' : ($health['coach'] ? 'Enabled and configured.' : 'Enabled but ANTHROPIC_API_KEY or COACH_MODEL is missing, so it stays unavailable.')],
                    ['Debug mode', ! $health['debug'], $health['debug'] ? 'APP_DEBUG is on. Turn it off in production.' : 'Off.'],
                    ['Guest mode', true, $health['guest_mode'] ? 'On: visitors can try the app without an account.' : 'Off.'],
                ] as [$label, $ok, $note])
                    <li class="flex gap-2"><x-icon :name="$ok ? 'check' : 'alert'" class="mt-0.5 size-4 shrink-0 {{ $ok ? 'text-neon' : 'text-amber-300' }}"/><span><strong>{{ $label }}.</strong> <span class="text-muted">{{ $note }}</span></span></li>
                @endforeach
            </ul>
            <p class="mt-3 text-xs text-muted">Environment: {{ $health['app_env'] }} · queue: {{ $health['queue'] }}</p>
        </section>
        <section class="glass p-5" aria-labelledby="priv-title">
            <h2 id="priv-title" class="text-sm font-bold uppercase">What admins can and cannot see</h2>
            <p class="mt-3 text-sm text-muted">Admins can see an account's name, email, role and how much it has done. They cannot open anyone's food log, measurements, progress photos, AI coach chats or reports. Posture rules and the plan affect every user, so edits are versioned or logged.</p>
        </section>
    </div>
</x-admin.shell>
