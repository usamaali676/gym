<x-admin.shell title="App content" section="content" lead="Switches and texts the apps read. None of them can change who is allowed to do what: that is decided by the server.">
    <x-admin.content-nav/>
    @if ($errors->any())<p class="mb-4 text-sm text-danger" role="alert">{{ $errors->first() }}</p>@endif
    <form method="POST" action="{{ route('admin.settings.update') }}" class="glass grid grid-cols-1 gap-4 p-5 md:grid-cols-2">
        @csrf @method('PUT')
        @foreach ($definitions as $key => $def)
            @php $field = 'settings['.str_replace('.', '_', $key).']'; @endphp
            @if ($def['type'] === 'bool')
                <label class="flex items-start gap-2 text-sm"><input type="hidden" name="{{ $field }}" value="0"><input type="checkbox" class="check mt-0.5" name="{{ $field }}" value="1" @checked($values[$key])><span><span class="font-semibold">{{ $def['label'] }}</span><span class="block text-xs text-muted">{{ $def['hint'] }}</span></span></label>
            @else
                <x-field :name="$field" :label="$def['label']" :value="$values[$key]" maxlength="255" :hint="$def['hint']"/>
            @endif
        @endforeach
        <div class="md:col-span-2"><button class="btn btn-primary">Save settings</button></div>
    </form>
</x-admin.shell>
