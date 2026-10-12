<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HelpArticle;
use App\Support\AdminAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class HelpArticleController extends Controller
{
    public function index(): View
    {
        return view('admin.content.help.index', ['items' => HelpArticle::query()->orderBy('category')->orderBy('sort_order')->orderBy('title')->paginate(40)]);
    }

    public function create(): View
    {
        return view('admin.content.help.form', ['item' => new HelpArticle(['category' => 'general', 'status' => 'draft'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $item = HelpArticle::create($this->data($request, null));
        AdminAudit::record('help.create', ['id' => $item->id]);

        return redirect()->route('admin.help.edit', $item)->with('status', 'Article saved.');
    }

    public function edit(HelpArticle $article): View
    {
        return view('admin.content.help.form', ['item' => $article]);
    }

    public function update(Request $request, HelpArticle $article): RedirectResponse
    {
        $article->update($this->data($request, $article));
        AdminAudit::record('help.update', ['id' => $article->id]);

        return redirect()->route('admin.help.edit', $article)->with('status', 'Article saved. Apps pick it up on their next refresh.');
    }

    public function destroy(HelpArticle $article): RedirectResponse
    {
        AdminAudit::record('help.delete', ['id' => $article->id]);
        $article->delete();

        return redirect()->route('admin.help.index')->with('status', 'Article deleted.');
    }

    /** @return array<string, mixed> */
    private function data(Request $request, ?HelpArticle $current): array
    {
        $request->merge(['slug' => Str::slug((string) ($request->input('slug') ?: $request->input('title')))]);

        return $request->validate([
            'title' => ['required', 'string', 'max:140'],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('help_articles', 'slug')->ignore($current?->id)],
            'category' => ['required', 'string', 'max:40'],
            'body' => ['required', 'string', 'max:20000'],
            'sort_order' => ['nullable', 'integer', 'between:0,999'],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
        ]) + ['sort_order' => 0];
    }
}
