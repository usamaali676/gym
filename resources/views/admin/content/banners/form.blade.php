@php $isNew = ! $item->exists; @endphp
<x-admin.shell :title="$isNew ? 'New banner' : 'Edit banner'" section="content">
    <x-admin.content-nav/>
    @if ($errors->any())<p class="mb-4 text-sm text-danger" role="alert">{{ $errors->first() }}</p>@endif
    <form method="POST" enctype="multipart/form-data" action="{{ $isNew ? route('admin.banners.store') : route('admin.banners.update', $item) }}" class="glass grid grid-cols-1 gap-3 p-5 md:grid-cols-2">
        @csrf @if(! $isNew) @method('PATCH') @endif
        <x-field name="title" label="Title" :value="$item->title" maxlength="100" required/>
        <x-field name="subtitle" label="Subtitle (optional)" :value="$item->subtitle" maxlength="160"/>
        <label class="block"><span class="label">Where it appears</span><select name="placement" class="select">@foreach (App\Models\Banner::PLACEMENTS as $p)<option value="{{ $p }}" @selected(old('placement', $item->placement) === $p)>{{ ucfirst($p) }}</option>@endforeach</select></label>
        <label class="block"><span class="label">Status</span><select name="status" class="select">@foreach (['draft' => 'Draft (hidden)', 'published' => 'Published', 'archived' => 'Archived'] as $k => $l)<option value="{{ $k }}" @selected(old('status', $item->status) === $k)>{{ $l }}</option>@endforeach</select></label>
        <x-field name="cta_label" label="Button label (optional)" :value="$item->cta_label" maxlength="40"/>
        <x-field name="cta_url" label="Button link" :value="$item->cta_url" maxlength="255" hint="https://… or an in-app link such as ironpulse://programs"/>
        <x-field name="starts_at" type="datetime-local" label="Show from (optional)" :value="$item->starts_at?->format('Y-m-d\TH:i')"/>
        <x-field name="ends_at" type="datetime-local" label="Hide after (optional)" :value="$item->ends_at?->format('Y-m-d\TH:i')"/>
        <x-field name="sort_order" type="number" label="Order" :value="$item->sort_order ?? 0" min="0" max="999"/>
        <label class="block"><span class="label">Image (JPEG, PNG or WebP, up to 3 MB)</span><input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="input"></label>
        @if ($item->image_path)<label class="flex items-center gap-2 text-sm md:col-span-2"><input type="checkbox" class="check" name="remove_image" value="1"> Remove the current image</label>@endif
        <x-admin.audience :plans="$plans" :selected="$item->audience ?? []"/>
        <div class="md:col-span-2"><button class="btn btn-primary">{{ $isNew ? 'Save banner' : 'Save changes' }}</button></div>
    </form>
    @unless ($isNew)<form method="POST" action="{{ route('admin.banners.destroy', $item) }}" class="mt-6" onsubmit="return confirm('Delete this banner?')">@csrf @method('DELETE')<button class="btn btn-danger btn-sm"><x-icon name="trash" class="size-4"/>Delete</button></form>@endunless
</x-admin.shell>
