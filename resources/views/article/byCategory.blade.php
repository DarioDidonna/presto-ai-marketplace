<x-layout :title="$category->translated_name" :description="__('ui.category_meta', ['category' => $category->translated_name])">

    <div class="position-relative overflow-hidden bg-navbar py-5 pt-custom-navbar border-bottom border-secondary-subtle">
        <div class="container position-relative z-1 py-4 text-center">
            <span
                class="badge bg-transparent border border-neon-cyan text-neon-cyan text-uppercase tracking-widest px-3 py-2 mb-3 fs-8 fw-semibold">
                <i class="{{ $category->icon ?? 'bi bi-tag' }} me-1"></i> {{ $articles->total() }}
                {{ $articles->total() == 1 ? __('ui.single_ad') : __('ui.ads') }}
            </span>
            <h1 class="display-4 fw-black text-white text-uppercase tracking-tight mb-0">
                {{ __('ui.category_articles') }}
                <span class="fst-italic text-glow text-neon-cyan">{{ $category->translated_name }}</span>
            </h1>
        </div>
    </div>

    <div class="article-index-wrapper py-5">
        <div class="container">

            @if ($articles->count() > 0)
                <div class="row g-4">
                    @foreach ($articles as $article)
                        <div class="col-12 col-md-6 col-lg-4 d-flex">
                            <x-card :article="$article" />
                        </div>
                    @endforeach
                </div>

                <div class="d-flex justify-content-center mt-5 pagination-cyber">
                    {{ $articles->links() }}
                </div>
            @else
                <div class="row justify-content-center py-5">
                    <div
                        class="col-12 col-md-6 text-center py-5 border border-dashed border-secondary rounded-4 bg-navbar-dark shadow">
                        <div class="fs-1 mb-3">
                            <i class="bi bi-tag-fill text-neon-amber"></i>
                        </div>
                        <h4 class="text-white fw-bold text-uppercase tracking-wide mb-4">
                            {{ __('ui.no_articles_in_category') }}
                        </h4>
                        @auth
                            <a class="btn btn-cyber-gradient text-uppercase tracking-wider fs-7 fw-bold"
                                href="{{ route('articles.create') }}">
                                <i class="bi bi-plus-circle me-2"></i>{{ __('ui.publish_article') }}
                            </a>
                        @endauth
                    </div>
                </div>
            @endif

        </div>
    </div>

</x-layout>
