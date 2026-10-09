<x-admin.shell title="Posture rules" lead="Each rule tells the Posture Lab which joint angles to watch and how to count reps. Saving writes a new version; earlier sessions keep the version they were scored with.">
    <div class="glass relative overflow-x-auto">
        <table class="w-full min-w-[40rem] text-left text-sm">
            <thead class="text-xs uppercase tracking-wider text-muted"><tr><th class="p-3" scope="col">Exercise</th><th class="p-3" scope="col">Tier</th><th class="p-3" scope="col">Camera</th><th class="p-3" scope="col">Version</th><th class="p-3" scope="col">Edited by an admin</th><th class="p-3" scope="col"><span class="sr-only">Edit</span></th></tr></thead>
            <tbody>
                @foreach ($rows as $row)
                    @php $r = $row['current']; @endphp
                    <tr class="border-t border-line">
                        <td class="p-3 font-semibold">{{ $r->exercise->name }}</td>
                        <td class="p-3 text-muted">{{ $r->tier->label() }}</td>
                        <td class="p-3 text-muted">{{ $r->camera_view->label() }}</td>
                        <td class="p-3 num">v{{ $r->version }}<span class="text-xs text-muted"> ({{ $row['versions'] }} saved)</span></td>
                        <td class="p-3 text-muted">{{ $r->updated_by ? 'Yes' : 'No, as seeded' }}</td>
                        <td class="p-3 text-right"><a class="btn btn-ghost btn-sm" href="{{ route('admin.posture.edit', $r->exercise) }}">Edit</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-admin.shell>
