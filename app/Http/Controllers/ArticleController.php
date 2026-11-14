<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Category;
use App\Support\ArticleFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ArticleController extends Controller
{
    public function create()
    {
        return view('article.create');
    }

    // Elenco annunci con ricerca testuale e filtri (categoria, prezzo, ordinamento)
    public function index(Request $request)
    {
        $filters = ArticleFilters::fromRequest($request);
        $articles = $filters->query()->paginate(12)->withQueryString();

        return view('article.index', compact('articles', 'filters'));
    }

    public function show(Article $article)
    {
        // 404 (non 403) per non rivelare l'esistenza di annunci non pubblicati: regola in ArticlePolicy::view
        abort_unless(Gate::allows('view', $article), 404);

        $article->load(['images', 'category', 'user' => fn ($user) => $user
            ->withAvg('reviewsReceived', 'rating')
            ->withCount('reviewsReceived')]);
        $inCart = (bool) request()->user()?->cart()->whereKey($article->id)->exists();

        return view('article.show', compact('article', 'inCart'));
    }

    // Permesso verificato sulla rotta: ->can('update', 'article')
    public function edit(Article $article)
    {
        return view('article.edit', compact('article'));
    }

    // Permesso verificato sulla rotta: ->can('delete', 'article')
    public function destroy(Article $article)
    {
        $article->deleteWithFiles();

        return redirect()->route('profile.show')->with('message', __('ui.article_deleted', ['title' => $article->title]));
    }

    public function byCategory(Category $category)
    {
        $articles = $category->articles()->accepted()->withCardRelations()->latest()->paginate(12);

        return view('article.byCategory', compact('articles', 'category'));
    }
}
