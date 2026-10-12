<x-admin.shell :title="'Rule: '.$exercise->name" section="posture" page="admin-posture" :heading="$exercise->name" :lead="'Currently version '.$rule->version.'. The JSON follows schema 1; the checker below runs the same validation the Posture Lab applies in the browser.'">
    <p class="mb-4"><a href="{{ route('admin.posture.index') }}" class="text-sm font-semibold text-neon underline underline-offset-4">All rules</a> · <a href="{{ route('posture.show', $exercise) }}" class="text-sm font-semibold text-neon underline underline-offset-4">Open in the lab</a></p>
    <script type="application/json" id="posture-admin-config">@json(['validateUrl' => route('admin.posture.validate')])</script>

    <form method="POST" action="{{ route('admin.posture.update', $exercise) }}" class="space-y-4" x-data="postureEditor">
        @csrf @method('PUT')
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="block"><span class="label">Tier</span><select name="tier" class="select">@foreach ($tiers as $t)<option value="{{ $t->value }}" @selected(old('tier', $rule->tier->value) === $t->value)>{{ $t->label() }}</option>@endforeach</select></label>
            <label class="block"><span class="label">Camera view</span><select name="camera_view" class="select">@foreach ($views as $v)<option value="{{ $v->value }}" @selected(old('camera_view', $rule->camera_view->value) === $v->value)>{{ $v->label() }}</option>@endforeach</select></label>
        </div>

        <label class="block"><span class="label">Config (JSON)</span>
            <textarea name="config_json" rows="22" spellcheck="false" class="textarea font-mono text-xs leading-relaxed" @input.debounce.400ms="check($event.target.value)" aria-describedby="cfg-status">{{ $configJson }}</textarea></label>
        <div id="cfg-status" role="status" aria-live="polite" class="text-sm">
            <p x-show="state === 'ok'" class="text-neon" x-cloak>Valid: the lab will accept this rule.</p>
            <ul x-show="state === 'bad'" x-cloak class="list-disc space-y-1 pl-5 text-danger"><template x-for="e in errors" :key="e"><li x-text="e"></li></template></ul>
        </div>
        @error('config_json')<ul class="list-disc space-y-1 pl-5 text-sm text-danger" role="alert">@foreach ($errors->get('config_json') as $m)<li>{{ $m }}</li>@endforeach</ul>@enderror

        <label class="block"><span class="label">Guidance (JSON, optional): camera set-up steps and accuracy notes</span>
            <textarea name="guidance_json" rows="10" spellcheck="false" class="textarea font-mono text-xs leading-relaxed">{{ $guidanceJson }}</textarea></label>
        @error('guidance_json')<p class="text-sm text-danger" role="alert">{{ $message }}</p>@enderror

        <div class="flex flex-wrap items-center gap-3">
            <button class="btn btn-primary" :disabled="state === 'bad'">Save as version {{ $versions->max('version') + 1 }}</button>
            <p class="text-xs text-muted">Thresholds here are biomechanical approximations. Test a change in the lab with a real camera before relying on it.</p>
        </div>
    </form>

    <section class="mt-8" aria-labelledby="ver-title">
        <h2 id="ver-title" class="mb-3 text-lg font-bold uppercase">Versions</h2>
        <ul class="space-y-2">
            @foreach ($versions as $v)
                <li class="glass flex flex-wrap items-center justify-between gap-3 p-3 text-sm">
                    <span><strong class="num">v{{ $v->version }}</strong> · {{ $v->tier->label() }}, {{ $v->camera_view->label() }} · {{ $v->updated_at->format('j M Y H:i') }} · {{ $v->updated_by ? 'edited in admin' : 'seeded' }} @if($v->is_active)<span class="chip !border-neon !text-neon">Live</span>@endif</span>
                    @unless ($v->is_active)
                        <form method="POST" action="{{ route('admin.posture.restore', [$exercise, $v]) }}" onsubmit="return confirm('Make version {{ $v->version }} live again as a new version?')">@csrf<button class="btn btn-ghost btn-sm">Restore as new version</button></form>
                    @endunless
                </li>
            @endforeach
        </ul>
    </section>
</x-admin.shell>
