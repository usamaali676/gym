@php $isNew = ! $item->exists; @endphp
<x-admin.shell :title="$isNew ? 'New announcement' : 'Edit announcement'" section="content">
    <x-admin.content-nav/>
    @if ($errors->any())<p class="mb-4 text-sm text-danger" role="alert">{{ $errors->first() }}</p>@endif
    <form method="POST" action="{{ $isNew ? route('admin.announcements.store') : route('admin.announcements.update', $item) }}" class="glass grid grid-cols-1 gap-3 p-5 md:grid-cols-2">
        @csrf @if(! $isNew) @method('PATCH') @endif
        <x-field name="title" label="Title" :value="$item->title" maxlength="120" required class="md:col-span-2"/>
        <label class="block md:col-span-2"><span class="label">Message</span><textarea name="body" rows="4" class="textarea input" maxlength="2000" required>{{ old('body', $item->body) }}</textarea></label>
        <label class="block"><span class="label">Type</span><select name="severity" class="select">@foreach (App\Models\Announcement::SEVERITIES as $s)<option value="{{ $s }}" @selected(old('severity', $item->severity) === $s)>{{ ucfirst($s) }}</option>@endforeach</select></label>
        <label class="block"><span class="label">Status</span><select name="status" class="select">@foreach (['draft' => 'Draft (hidden)', 'published' => 'Published', 'archived' => 'Archived'] as $k => $l)<option value="{{ $k }}" @selected(old('status', $item->status) === $k)>{{ $l }}</option>@endforeach</select></label>
        <x-field name="cta_label" label="Button label (optional)" :value="$item->cta_label" maxlength="40"/>
        <x-field name="cta_url" label="Button link" :value="$item->cta_url" maxlength="255" hint="https://… or an in-app link such as ironpulse://membership"/>
        <x-field name="starts_at" type="datetime-local" label="Show from (optional)" :value="$item->starts_at?->format('Y-m-d\TH:i')"/>
        <x-field name="ends_at" type="datetime-local" label="Hide after (optional)" :value="$item->ends_at?->format('Y-m-d\TH:i')"/>
        <label class="flex items-center gap-2 text-sm md:col-span-2"><input type="hidden" name="pinned" value="0"><input type="checkbox" class="check" name="pinned" value="1" @checked(old('pinned', $item->pinned))> Pin to the top</label>
        <x-admin.audience :plans="$plans" :selected="$item->audience ?? []"/>
        <div class="md:col-span-2"><button class="btn btn-primary">{{ $isNew ? 'Save announcement' : 'Save changes' }}</button></div>
    </form>
    @unless ($isNew)
        <section class="glass mt-6 p-5" aria-labelledby="push-title">
            <h2 id="push-title" class="text-sm font-bold uppercase">Push notification</h2>
            @if ($item->push_sent_at)
                <p class="mt-2 text-sm text-muted">Sent {{ $item->push_sent_at->diffForHumans() }}.</p>
            @elseif (! config('ironpulse.push.enabled'))
                <p class="mt-2 text-sm text-muted">Push is not set up on this server, so announcements reach members inside the app only. Configure Firebase (see the mobile docs) to notify members' phones.</p>
            @else
                <p class="mt-2 text-sm text-muted">Sends once to members in the audience who allowed announcement notifications. On lock screens they see a generic message unless they chose to show details.</p>
                <form method="POST" action="{{ route('admin.announcements.push', $item) }}" class="mt-3">@csrf<button class="btn btn-ghost btn-sm" @disabled($item->status !== 'published')>Send notification</button></form>
            @endif
        </section>
    @endunless
    @unless ($isNew)<form method="POST" action="{{ route('admin.announcements.destroy', $item) }}" class="mt-6" onsubmit="return confirm('Delete this announcement?')">@csrf @method('DELETE')<button class="btn btn-danger btn-sm"><x-icon name="trash" class="size-4"/>Delete</button></form>@endunless
</x-admin.shell>
