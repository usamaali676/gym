<x-admin.shell title="Foods" lead="The shared food database (values per 100 g). Foods that people add for themselves are private to them and are not listed here.">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <label class="min-w-48"><span class="label !mb-1">Search</span><input type="search" name="q" value="{{ $q }}" class="input !min-h-10"></label>
            <label><span class="label !mb-1">Category</span><select name="category" class="select !min-h-10"><option value="">All</option>@foreach ($categories as $c)<option value="{{ $c }}" @selected($category === $c)>{{ str_replace('_', ' ', $c) }}</option>@endforeach</select></label>
            <button class="btn btn-ghost btn-sm">Filter</button>
        </form>
        <a href="{{ route('admin.foods.create') }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="size-4"/>New food</a>
    </div>
    <div class="glass relative overflow-x-auto">
        <table class="w-full min-w-[44rem] text-left text-sm">
            <thead class="text-xs uppercase tracking-wider text-muted"><tr><th class="p-3" scope="col">Food</th><th class="p-3" scope="col">Category</th><th class="p-3" scope="col">kcal</th><th class="p-3" scope="col">P</th><th class="p-3" scope="col">C</th><th class="p-3" scope="col">F</th><th class="p-3" scope="col"><span class="sr-only">Edit</span></th></tr></thead>
            <tbody>
                @foreach ($foods as $f)
                    <tr class="border-t border-line">
                        <td class="p-3 font-semibold">{{ $f->name }}<span class="block text-xs font-normal text-muted">{{ $f->diet_type }}{{ $f->brand ? ' · '.$f->brand : '' }}</span></td>
                        <td class="p-3 text-muted">{{ str_replace('_', ' ', $f->category) }}</td>
                        <td class="p-3 num">{{ round($f->kcal_100g) }}</td><td class="p-3 num">{{ $f->protein_100g }}</td><td class="p-3 num">{{ $f->carbs_100g }}</td><td class="p-3 num">{{ $f->fat_100g }}</td>
                        <td class="p-3 text-right"><a class="btn btn-ghost btn-sm" href="{{ route('admin.foods.edit', $f) }}">Edit</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $foods->links() }}</div>
</x-admin.shell>
