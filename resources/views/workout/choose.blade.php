<x-layouts.app title="Train" page="workout-choose">
    <div class="mb-6">
        <p class="eyebrow">Train</p>
        <h1 class="mt-2 text-2xl font-black uppercase sm:text-3xl">Pick a workout</h1>
        <p class="mt-1 max-w-xl text-sm text-muted">Today is not a training day on your plan. Muscle is built during recovery, not in the gym. If you still want to train, or you missed a session this week, choose one below.</p>
    </div>

    @if ($options->isEmpty())
        <div class="glass p-8 text-center">
            <x-icon name="moon" class="mx-auto size-10 text-neon"/>
            <h2 class="mt-3 text-lg font-bold uppercase">Nothing to catch up on</h2>
            <p class="mt-1 text-sm text-muted">Every training day this week is done. Enjoy the recovery.</p>
            <div class="mt-5 flex flex-wrap justify-center gap-2">
                <a class="btn btn-primary" href="{{ route('plan.index') }}"><x-icon name="calendar" class="size-4"/>See the plan</a>
                @if (route_if('recovery.index'))<a class="btn btn-ghost" href="{{ route('recovery.index') }}"><x-icon name="stretch" class="size-4"/>Recovery routine</a>@endif
            </div>
        </div>
    @else
        <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @foreach ($options as $o)
                <li>
                    <a href="{{ route('workout.start', ['day' => $o['day']->day_of_week, 'date' => $o['date']->toDateString()]) }}" class="glass glass-hover flex items-center gap-4 p-4">
                        <div class="grid w-14 shrink-0 place-items-center rounded-xl border border-line bg-black/20 py-2 text-center"><span class="text-[0.65rem] font-bold uppercase text-muted">{{ $o['date']->format('D') }}</span><span class="num text-2xl leading-none">{{ $o['date']->format('j') }}</span></div>
                        <div class="min-w-0 flex-1">
                            <p class="font-black uppercase">{{ $o['day']->label }} @if ($o['day']->focusLabel())<span class="text-neon">{{ $o['day']->focusLabel() }}</span>@endif</p>
                            <x-status :status="$o['status']" class="mt-1"/>
                        </div>
                        <x-icon name="play" class="size-5 text-neon"/>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.app>
