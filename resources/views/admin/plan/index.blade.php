<x-admin.shell title="Plan" :lead="$plan->name.' (version '.$plan->version.'). Editing changes the plan for everyone who follows it. Existing workout history is never rewritten.'">
    <ul class="grid grid-cols-1 gap-3 md:grid-cols-2">
        @foreach ($plan->days as $day)
            <li class="glass p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wider text-muted">{{ $day->name }}</p>
                        <h2 class="truncate text-base font-bold">{{ $day->title() }}</h2>
                    </div>
                    <a href="{{ route('admin.plan.edit', $day) }}" class="btn btn-ghost btn-sm shrink-0"><x-icon name="edit" class="size-4"/>Edit</a>
                </div>
                @if ($day->is_rest)
                    <p class="mt-2 text-sm text-muted">Rest day</p>
                @else
                    <ol class="mt-2 list-decimal space-y-0.5 pl-5 text-sm text-muted marker:text-neon">
                        @foreach ($day->items as $item)<li><span class="text-ink">{{ $item->displayName() }}</span> · {{ $item->sets }} x {{ $item->repsLabel() }}</li>@endforeach
                    </ol>
                @endif
            </li>
        @endforeach
    </ul>
</x-admin.shell>
