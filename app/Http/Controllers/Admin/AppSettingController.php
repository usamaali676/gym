<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Support\AdminAudit;
use App\Support\AppSettingKeys;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The closed list of switches and texts the apps read (see AppSettingKeys). */
class AppSettingController extends Controller
{
    public function edit(): View
    {
        return view('admin.content.settings', [
            'definitions' => AppSettingKeys::all(),
            'values' => AppSettingKeys::publicValues(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (AppSettingKeys::all() as $key => $def) {
            $field = 'settings.'.str_replace('.', '_', $key);
            $rules[$field] = match ($def['type']) {
                'bool' => ['sometimes', 'boolean'],
                'url' => ['nullable', 'string', 'max:255', 'regex:#^https://\S+$#i'],
                default => ['nullable', 'string', 'max:255'],
            };
        }
        $input = $request->validate($rules)['settings'] ?? [];

        foreach (AppSettingKeys::all() as $key => $def) {
            $raw = $input[str_replace('.', '_', $key)] ?? null;
            $value = $def['type'] === 'bool' ? $request->boolean('settings.'.str_replace('.', '_', $key)) : (($raw === '' || $raw === null) ? null : $raw);

            $setting = AppSetting::firstOrNew(['key' => $key]);
            $setting->fill(['value' => $value, 'is_public' => true]);
            $setting->updated_by = $request->user()->id;
            $setting->save();
        }
        AdminAudit::record('settings.update');

        return back()->with('status', 'Settings saved. Apps read them on launch and when they come back to the foreground.');
    }
}
