<x-layout :title="$filters->query ? __('ui.search_title', ['query' => $filters->query]) : __('ui.all_ads_title')">

    <div class="position-relative overflow-hidden bg-navbar py-5 pt-custom-navbar border-bottom border-secondary-subtle">
        <div
            class="position-absolute top-50 start-50 translate-middle w-50 h-50 bg-glow-radial opacity-10 pointer-events-none">
        </div>

        <div class="container position-relative z-1 py-4 text-center">
            <span
                class="badge bg-transparent border border-neon-cyan text-neon-cyan text-uppercase tracking-widest px-3 py-2 mb-3 fs-8 fw-semibold">
                {{ __('ui.global_board') }}
            </span>
            <h1 class="display-4 fw-black text-white text-uppercase tracking-tight mb-2 text-break">
                @if ($filters->query)
                    {{ __('ui.results_for') }}: <span class="text-glow text-neon-cyan text-none-transform">"{{ $filters->query }}"</span>
                @else
                    {{ __('ui.all_ads') }} <span class="text-glow text-neon-cyan">{{ __('ui.announcements') }}</span>
                @endif
            </h1>
            <p class="text-muted-custom fs-5 max-w-600 mx-auto mb-0">
                {{ __('ui.explore_platform') }}
            </p>
        </div>
    </div>

    <div class="article-index-wrapper py-5">
        <div class="container">

            {{-- Filtri: form GET, combinabili con la ricerca testuale --}}
            <form action="{{ route('articles.index') }}" method="GET" role="search" aria-label="{{ __('ui.filters') }}"
                class="p-3 p-md-4 rounded-3 border border-secondary-subtle bg-navbar-card shadow-sm mb-4">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-lg-3">
                        <label for="filter_query" class="form-label text-muted-custom fs-8 text-uppercase tracking-wide fw-semibold">{{ __('ui.search') }}</label>
                        <input type="search" name="query" id="filter_query" value="{{ $filters->query }}" maxlength="100"
                            placeholder="{{ __('ui.search_placeholder') }}" class="form-control bg-dark border-secondary text-white">
                    </div>
                    <div class="col-12 col-sm-6 col-lg-2">
                        <label for="filter_category" class="form-label text-muted-custom fs-8 text-uppercase tracking-wide fw-semibold">{{ __('ui.category') }}</label>
                        <select name="category" id="filter_category" class="form-select bg-dark border-secondary text-white">
                            <option value="">{{ __('ui.all_categories') }}</option>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->id }}" @selected($filters->category === $cat->id)>{{ $cat->translated_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-sm-3 col-lg-2">
                        <label for="filter_min_price" class="form-label text-muted-custom fs-8 text-uppercase tracking-wide fw-semibold">{{ __('ui.min_price') }}</label>
                        <div class="input-group">
                            <span class="input-group-text bg-dark border-secondary text-neon-cyan">€</span>
                            <input type="number" name="min_price" id="filter_min_price" min="0" step="0.01" inputmode="decimal"
                                value="{{ $filters->minPrice }}" class="form-control bg-dark border-secondary text-white">
                        </div>
                    </div>
                    <div class="col-6 col-sm-3 col-lg-2">
                        <label for="filter_max_price" class="form-label text-muted-custom fs-8 text-uppercase tracking-wide fw-semibold">{{ __('ui.max_price') }}</label>
                        <div class="input-group">
                            <span class="input-group-text bg-dark border-secondary text-neon-cyan">€</span>
                            <input type="number" name="max_price" id="filter_max_price" min="0" step="0.01" inputmode="decimal"
                                value="{{ $filters->maxPrice }}" class="form-control bg-dark border-secondary text-white">
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-2">
                        <label for="filter_sort" class="form-label text-muted-custom fs-8 text-uppercase tracking-wide fw-semibold">{{ __('ui.sort_by') }}</label>
                        <select name="sort" id="filter_sort" class="form-select bg-dark border-secondary text-white">
                            @foreach (\App\Support\ArticleFilters::SORTS as $sort)
                                @continue($sort === 'relevance' && ! $filters->query)
                                <option value="{{ $sort }}" @selected($filters->sort === $sort)>{{ __('ui.sort_' . $sort) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-1 d-grid">
                        <button type="submit" class="btn btn-cyber-gradient fw-bold" aria-label="{{ __('ui.apply_filters') }}">
                            <i class="bi bi-funnel"></i><span class="d-lg-none ms-2">{{ __('ui.apply_filters') }}</span>
                        </button>
                    </div>
                </div>
            </form>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                <p class="text-muted-custom fs-7 mb-0" role="status">
                    <span class="text-white fw-bold font-monospace">{{ $articles->total() }}</span>
                    {{ $articles->total() == 1 ? __('ui.single_result') : __('ui.results_found') }}
                </p>
                @if ($filters->isFiltered())
                    <a href="{{ route('articles.index') }}" class="btn btn-sm btn-outline-secondary text-white">
                        <i class="bi bi-x-circle me-1"></i>{{ __('ui.reset_filters') }}
                    </a>
                @endif
            </div>

            @if ($articles->count() > 0)
                <div class="row g-4">
                    @foreach ($articles as $article)
                        <div class="col-12 col-md-6 col-lg-4 d-flex">
                            <x-card :article="$article" />
                        </div>
                    @endforeach
                </div>

                @if ($articles->hasPages())
                    <div class="d-flex justify-content-center mt-5 pagination-cyber">
                        {{ $articles->links() }}
                    </div>
                @endif
            @else
                <div class="row justify-content-center py-5">
                    <div
                        class="col-12 col-md-6 text-center py-5 border border-dashed border-secondary rounded-4 bg-navbar-dark shadow">
                        <div class="fs-1 text-muted mb-3">
                            <i class="bi {{ $filters->isFiltered() ? 'bi-search' : 'bi-folder-x' }} text-neon-cyan"></i>
                        </div>
                        @if ($filters->isFiltered())
                            <h2 class="h4 text-white fw-bold text-uppercase tracking-wide mb-2">{{ __('ui.no_articles_match') }}</h2>
                            <p class="text-muted-custom fs-7 mb-4">{{ __('ui.search_tip') }}</p>
                            <a href="{{ route('articles.index') }}" class="btn btn-outline-neon-cyan text-uppercase tracking-wider fs-7 fw-bold px-4 py-2">
                                <i class="bi bi-x-circle me-2"></i>{{ __('ui.reset_filters') }}
                            </a>
                        @else
                            <h2 class="h4 text-white fw-bold text-uppercase tracking-wide mb-2">{{ __('ui.no_ads_found') }}</h2>
                            <p class="text-muted-custom fs-7 mb-4">{{ __('ui.no_ads_message') }}</p>
                            <a href="{{ route('articles.create') }}"
                                class="btn btn-outline-neon-cyan text-uppercase tracking-wider fs-7 fw-bold px-4 py-2">
                                <i class="bi bi-plus-circle me-2"></i> {{ __('ui.create_ad') }}
                            </a>
                        @endif
                    </div>
                </div>
            @endif

        </div>
    </div>

</x-layout>
