<x-layout>

    @if (session()->has('errorMessage'))
        <div class="container"><div class="row justify-content-center mt-5 pt-5">
            <div class="col-12 col-md-6">
                <div
                    class="alert alert-danger text-center shadow rounded border-0 bg-danger bg-opacity-25 text-white d-flex align-items-center justify-content-center p-3">
                    <i class="bi bi-x-circle-fill me-2 fs-5 text-danger"></i>
                    <span class="font-monospace fs-7">{{ session('errorMessage') }}</span>
                </div>
            </div>
        </div></div>
    @endif

    @if (session()->has('message'))
        <div class="container"><div class="row justify-content-center mt-5 pt-5">
            <div class="col-12 col-md-8 col-lg-6">
                <div
                    class="alert bg-dark text-white shadow-lg rounded border border-info d-flex align-items-center justify-content-between p-3 font-monospace animate-fade-in">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-check-circle text-neon-cyan fs-4 me-3"></i>
                        <div>
                            <strong class="text-neon-cyan d-block text-uppercase tracking-wider fs-7 mb-1">{{ __('ui.notice') }}</strong>
                            <span class="fs-7 text-white-50">{{ session('message') }}</span>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white shadow-none fs-8 ps-2"
                        data-bs-dismiss="alert" aria-label="{{ __('ui.close') }}"></button>
                </div>
            </div>
        </div></div>
    @endif

    <x-header />

    <x-categories />

    <x-live-activity />

    <section class="position-relative overflow-hidden py-5 bg-main-dark">

        <div class="cyber-glow-1"></div>
        <div class="cyber-glow-2"></div>

        <div class="container position-relative z-1 my-4">

            <div class="row align-items-end mb-5">
                <div class="col-12 col-md-8">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="cyber-badge-pulse"></span>
                        <span class="text-neon-cyan text-uppercase tracking-widest fs-8 fw-bold">{{ __('ui.live_feed') }}</span>
                    </div>
                    <h2 class="display-5 fw-black text-white text-uppercase tracking-tight m-0">
                        <span class="text-glow text-neon-cyan">{{ __('ui.latest_ads') }}</span>
                    </h2>
                    <p class="text-muted-custom fs-6 m-0 mt-2 max-w-500">
                        {{ __('ui.find_deal') }}
                    </p>
                </div>
                <div class="col-12 col-md-4 text-md-end mt-3 mt-md-0">
                    <a href="{{ route('articles.create') }}"
                        class="btn btn-cyber-gradient text-uppercase text-light tracking-wider fs-7 fw-bold">
                        <i class="bi bi-plus-circle-fill me-2"></i>{{ __('ui.insert') }}
                    </a>
                </div>
            </div>

            <div class="row g-4">
                @foreach ($articles as $article)
                    <div class="col-12 col-md-6 col-lg-4 d-flex animate-fade-in">
                        <x-card :article="$article" />
                    </div>
                @endforeach
            </div>

        </div>
    </section>

    <x-how-it-works />

    <x-revisor-cta />

</x-layout>
