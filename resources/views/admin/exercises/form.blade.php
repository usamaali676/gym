@php
    $isNew = ! $exercise->exists;
    $val = fn (string $key, $default = null) => old($key, $exercise->{$key} ?? $default);
    $raw = fn (string $key) => ($v = $val($key)) instanceof BackedEnum ? $v->value : $v;
    $cues = old('form_cues', is_array($exercise->form_cues) ? implode("\n", $exercise->form_cues) : '');
    $mistakes = old('common_mistakes', is_array($exercise->common_mistakes) ? implode("\n", $exercise->common_mistakes) : '');
    $chosenEquipment = old('equipment', $exercise->equipment ?? []);
    $chosenSecondary = old('secondary_groups', $exercise->secondary_groups ?? []);
@endphp
<x-admin.shell :title="$isNew ? 'New exercise' : 'Edit '.$exercise->name" section="exercises" :heading="$isNew ? 'New exercise' : $exercise->name">
    <p class="mb-4"><a href="{{ route('admin.exercises.index') }}" class="text-sm font-semibold text-neon underline underline-offset-4">All exercises</a>@unless($isNew) · <a href="{{ route('library.show', $exercise) }}" class="text-sm font-semibold text-neon underline underline-offset-4">View in library</a>@endunless</p>
    @if ($errors->any())<p class="mb-4 text-sm text-danger" role="alert">Please fix the highlighted fields: {{ $errors->first() }}</p>@endif

    <form method="POST" action="{{ $isNew ? route('admin.exercises.store') : route('admin.exercises.update', $exercise) }}" class="space-y-6">
        @csrf @if(! $isNew) @method('PATCH') @endif
        <section class="glass grid grid-cols-1 gap-3 p-5 sm:grid-cols-2 lg:grid-cols-3" aria-label="Basics">
            <x-field name="name" label="Name" :value="$exercise->name" maxlength="120" required class="lg:col-span-2"/>
            <x-field name="slug" label="Slug (address)" :value="$exercise->slug" maxlength="120" hint="Leave blank to build it from the name."/>
            <label class="block"><span class="label">Kind</span><select name="kind" class="select">@foreach ($kinds as $k)<option value="{{ $k->value }}" @selected($raw('kind') === $k->value)>{{ $k->label() }}</option>@endforeach</select></label>
            <label class="block"><span class="label">Primary muscle</span><select name="muscle_group" class="select">@foreach ($groups as $g)<option value="{{ $g->value }}" @selected($raw('muscle_group') === $g->value)>{{ $g->label() }}</option>@endforeach</select></label>
            <label class="block"><span class="label">Mechanic</span><select name="mechanic" class="select">@foreach ($mechanics as $m)<option value="{{ $m->value }}" @selected($raw('mechanic') === $m->value)>{{ $m->label() }}</option>@endforeach</select></label>
            <label class="block"><span class="label">Difficulty</span><select name="difficulty" class="select">@foreach ($difficulties as $d)<option value="{{ $d->value }}" @selected($raw('difficulty') === $d->value)>{{ $d->label() }}</option>@endforeach</select></label>
            <label class="block"><span class="label">Mobility type (mobility only)</span><select name="mobility_type" class="select"><option value="">None</option>@foreach (['dynamic' => 'Dynamic (warm-up)', 'static' => 'Static (cool-down)'] as $k => $l)<option value="{{ $k }}" @selected($raw('mobility_type') === $k)>{{ $l }}</option>@endforeach</select></label>
            <x-field name="demo_key" label="3D demo key" :value="$exercise->demo_key" maxlength="80" hint="Blank uses the slug."/>
        </section>

        <section class="glass p-5" aria-label="Muscles and equipment">
            <fieldset><legend class="label">Secondary muscles</legend>
                <div class="flex flex-wrap gap-x-5 gap-y-2">@foreach ($groups as $g)<label class="flex items-center gap-2 text-sm"><input type="checkbox" class="check" name="secondary_groups[]" value="{{ $g->value }}" @checked(in_array($g->value, (array) $chosenSecondary, true))> {{ $g->label() }}</label>@endforeach</div></fieldset>
            <fieldset class="mt-4"><legend class="label">Equipment needed</legend>
                <div class="flex flex-wrap gap-x-5 gap-y-2">@foreach ($equipment as $tag)<label class="flex items-center gap-2 text-sm"><input type="checkbox" class="check" name="equipment[]" value="{{ $tag }}" @checked(in_array($tag, (array) $chosenEquipment, true))> {{ str_replace('_', ' ', $tag) }}</label>@endforeach</div></fieldset>
        </section>

        <section class="glass grid grid-cols-2 gap-3 p-5 md:grid-cols-4 lg:grid-cols-6" aria-label="Timing">
            <x-field name="rest_min_seconds" type="number" label="Rest min (s)" :value="$val('rest_min_seconds', 60)" min="0" max="600"/>
            <x-field name="rest_max_seconds" type="number" label="Rest max (s)" :value="$val('rest_max_seconds', 90)" min="0" max="600"/>
            <x-field name="default_rest_seconds" type="number" label="Default rest (s)" :value="$val('default_rest_seconds', 75)" min="0" max="600"/>
            <x-field name="default_hold_seconds" type="number" label="Hold (s)" :value="$exercise->default_hold_seconds" min="5" max="300" hint="Timed moves"/>
            <label class="flex items-center gap-2 self-end pb-3 text-sm"><input type="hidden" name="is_timed" value="0"><input type="checkbox" class="check" name="is_timed" value="1" @checked(old('is_timed', $exercise->is_timed))> Timed (hold)</label>
            <label class="flex items-center gap-2 self-end pb-3 text-sm"><input type="hidden" name="per_side" value="0"><input type="checkbox" class="check" name="per_side" value="1" @checked(old('per_side', $exercise->per_side))> Each side</label>
        </section>

        <section class="glass grid grid-cols-1 gap-3 p-5 md:grid-cols-2" aria-label="Instructions">
            <label class="block md:col-span-2"><span class="label">Description</span><textarea name="description" rows="3" class="textarea" maxlength="2000">{{ old('description', $exercise->description) }}</textarea></label>
            <label class="block"><span class="label">Form cues (one per line)</span><textarea name="form_cues" rows="6" class="textarea" maxlength="2000">{{ $cues }}</textarea></label>
            <label class="block"><span class="label">Common mistakes (one per line)</span><textarea name="common_mistakes" rows="6" class="textarea" maxlength="2000">{{ $mistakes }}</textarea></label>
            <x-field name="breathing" label="Breathing" :value="$exercise->breathing" maxlength="500" class="md:col-span-2"/>
        </section>

        <div class="flex flex-wrap items-center gap-4">
            <label class="flex items-center gap-2 text-sm"><span class="label !mb-0">Status</span>
                <select name="status" class="select !min-h-10 !w-auto">@foreach (['draft' => 'Draft (hidden everywhere)', 'published' => 'Published', 'archived' => 'Archived (hidden, history kept)'] as $k => $l)<option value="{{ $k }}" @selected(old('status', $exercise->status ?? 'published') === $k)>{{ $l }}</option>@endforeach</select></label>
            <label class="flex items-center gap-2 text-sm"><span class="label !mb-0">Who can open it</span>
                <select name="required_entitlement" class="select !min-h-10 !w-auto"><option value="">Everyone</option><option value="library.premium" @selected(old('required_entitlement', $exercise->required_entitlement) === 'library.premium')>Members with the premium library</option></select></label>
            <button class="btn btn-primary">{{ $isNew ? 'Create exercise' : 'Save exercise' }}</button>
        </div>
    </form>

    @unless ($isNew)
        <section class="glass mt-8 p-5" aria-labelledby="media-title">
            <h2 id="media-title" class="text-sm font-bold uppercase">Demonstration media</h2>
            <p class="mt-1 max-w-3xl text-sm text-muted">Short looping clips and images the mobile apps show for this exercise. One file per type and camera view; uploading again replaces it. MP4 or WebM video, JPEG, PNG or WebP images, up to 60 MB.</p>
            @if ($exercise->media->isNotEmpty())
                <ul class="mt-3 divide-y divide-line text-sm">
                    @foreach ($exercise->media as $m)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span><strong>{{ str_replace('_', ' ', $m->kind) }}</strong>{{ $m->view ? ' · '.$m->view.' view' : '' }} <span class="text-muted">· {{ $m->mime }} · {{ number_format($m->bytes / 1024) }} KB · {{ ucfirst($m->status) }}</span></span>
                            <span class="flex gap-2">
                                <form method="POST" action="{{ route('admin.exercise-media.update', $m) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="{{ $m->status === 'published' ? 'draft' : 'published' }}"><button class="btn btn-ghost btn-sm">{{ $m->status === 'published' ? 'Hide' : 'Publish' }}</button></form>
                                <form method="POST" action="{{ route('admin.exercise-media.destroy', $m) }}" onsubmit="return confirm('Remove this file?')">@csrf @method('DELETE')<button class="btn btn-danger btn-sm">Remove</button></form>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
            <form method="POST" enctype="multipart/form-data" action="{{ route('admin.exercises.media.store', $exercise) }}" class="mt-4 flex flex-wrap items-end gap-3">
                @csrf
                <label><span class="label !mb-1">Type</span><select name="kind" class="select !min-h-10">@foreach (['demo_video' => 'Demo video', 'instruction_video' => 'Instruction video', 'image' => 'Image', 'thumbnail' => 'Thumbnail'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
                <label><span class="label !mb-1">Camera view</span><select name="view" class="select !min-h-10"><option value="">Not applicable</option><option value="angle">Angle</option><option value="side">Side</option><option value="front">Front</option></select></label>
                <label class="min-w-56 flex-1"><span class="label !mb-1">File</span><input type="file" name="file" class="input !min-h-10" accept="video/mp4,video/webm,image/jpeg,image/png,image/webp" required></label>
                <button class="btn btn-primary btn-sm">Upload</button>
            </form>
            @error('file')<p class="mt-2 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
        </section>

        <form method="POST" action="{{ route('admin.exercises.destroy', $exercise) }}" class="mt-8" onsubmit="return confirm('Remove this exercise? If it is used in the plan or in anyone\'s history it is hidden instead of deleted.')">
            @csrf @method('DELETE')
            <button class="btn btn-danger btn-sm"><x-icon name="trash" class="size-4"/>Remove exercise</button>
            @if ($exercise->plan_items_count)<span class="ml-2 text-xs text-muted">Used on {{ $exercise->plan_items_count }} plan day(s).</span>@endif
        </form>
    @endunless
</x-admin.shell>
