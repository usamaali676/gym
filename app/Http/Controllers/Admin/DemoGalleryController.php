<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Every exercise's 3D animation at four points of its loop, for reviewing demos. */
class DemoGalleryController extends Controller
{
    public function __invoke(Request $request): View
    {
        $kind = $request->query('kind');
        $exercises = Exercise::query()->active()
            ->when(in_array($kind, ['plan', 'alternative', 'helper', 'mobility'], true), fn ($q) => $q->where('kind', $kind))
            ->orderByRaw("CASE kind WHEN 'plan' THEN 0 WHEN 'alternative' THEN 1 WHEN 'helper' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->get();

        return view('admin.demos', ['exercises' => $exercises, 'kind' => $kind]);
    }
}
