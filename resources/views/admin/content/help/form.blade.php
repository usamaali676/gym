@php $isNew = ! $item->exists; @endphp
<x-admin.shell :title="$isNew ? 'New help article' : 'Edit help article'" section="content">
    <x-admin.content-nav/>
    @if ($errors->any())<p class="mb-4 text-sm text-danger" role="alert">{{ $errors->first() }}</p>@endif
    <form method="POST" action="{{ $isNew ? route('admin.help.store') : route('admin.help.update', $item) }}" class="glass grid grid-cols-1 gap-3 p-5 md:grid-cols-2">
        @csrf @if(! $isNew) @method('PATCH') @endif
        <x-field name="title" label="Title" :value="$item->title" maxlength="140" required class="md:col-span-2"/>
        <x-field name="slug" label="Address" :value="$item->slug" maxlength="120" hint="Leave empty to derive it from the title."/>
        <x-field name="category" label="Category" :value="$item->category" maxlength="40" required/>
        <label class="block md:col-span-2"><span class="label">Text</span><textarea name="body" rows="12" class="textarea input" maxlength="20000" required>{{ old('body', $item->body) }}</textarea><span class="hint block">Plain paragraphs, **bold**, lists starting with "- " and [links](https://…) are shown formatted in the apps.</span></label>
        <x-field name="sort_order" type="number" label="Order within the category" :value="$item->sort_order ?? 0" min="0" max="999"/>
        <label class="block"><span class="label">Status</span><select name="status" class="select">@foreach (['draft' => 'Draft (hidden)', 'published' => 'Published', 'archived' => 'Archived'] as $k => $l)<option value="{{ $k }}" @selected(old('status', $item->status) === $k)>{{ $l }}</option>@endforeach</select></label>
        <div class="md:col-span-2"><button class="btn btn-primary">{{ $isNew ? 'Save article' : 'Save changes' }}</button></div>
    </form>
    @unless ($isNew)<form method="POST" action="{{ route('admin.help.destroy', $item) }}" class="mt-6" onsubmit="return confirm('Delete this article?')">@csrf @method('DELETE')<button class="btn btn-danger btn-sm"><x-icon name="trash" class="size-4"/>Delete</button></form>@endunless
</x-admin.shell>
