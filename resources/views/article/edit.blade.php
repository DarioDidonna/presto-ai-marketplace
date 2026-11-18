<x-layout :title="__('ui.edit_ad')">

    <div class="article-create-wrapper m-0 mt-4">

        <div class="card bg-navbar border-neon-cyan shadow-lg p-4">

            <div class="card-header border-0 bg-transparent pb-0">
                <a href="{{ route('articles.show', $article) }}"
                    class="text-neon-cyan text-decoration-none text-uppercase tracking-wider fs-8 fw-bold hover-back d-inline-block mb-3">
                    <i class="bi bi-arrow-left me-2"></i>{{ __('ui.back_to_ad') }}
                </a>
                <h1 class="h3 fw-black text-white text-uppercase tracking-tight mb-1">
                    {{ __('ui.edit') }} <span class="text-glow text-neon-cyan">{{ __('ui.ad') }}</span>
                </h1>
                <p class="text-muted-custom fs-7 mb-0">
                    <i class="bi bi-info-circle me-1 text-neon-amber"></i>{{ __('ui.edit_ad_review_notice') }}
                </p>
            </div>

            <div class="card-body pt-4">
                <livewire:edit-article-form :article="$article" />
            </div>

        </div>

    </div>

</x-layout>
