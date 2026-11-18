<section class="presto-revisor-cta py-5 position-relative overflow-hidden" id="revisorCtaSection">
    <div class="revisor-radial-glow"></div>

    <div class="container position-relative z-index-2">
        <div class="row justify-content-center">
            <div class="col-12 col-xl-10">

                <div
                    class="revisor-card p-4 p-md-5 rounded shadow-lg border border-secondary-subtle text-center text-md-start">
                    <div class="row align-items-center gy-4">

                        <div class="col-12 col-md-8">
                            <div
                                class="d-inline-flex align-items-center gap-2 revisor-badge px-3 py-1 rounded-pill mb-3 mx-auto mx-md-0">
                                <i class="bi bi-shield-fill text-neon-amber fs-7 animate-pulse"></i>
                                <span class="text-uppercase tracking-wider fs-8 fw-bold text-white">{{ __('ui.join_community_badge') }}</span>
                            </div>

                            <h2 class="fw-black text-white text-uppercase tracking-tight display-6 mb-3">
                                {{ __('ui.become_revisor_title') }} <span class="text-glow-amber text-neon-amber">{{ __('ui.revisor') }}</span> {{ __('ui.of_presto') }}
                            </h2>

                            <p class="text-muted-custom fs-6 mb-0 max-w-620">
                                {{ __('ui.become_revisor_description') }}
                            </p>
                        </div>

                        <div class="col-12 col-md-4 text-center text-md-end">
                            <form action="{{ route('revisor.apply') }}" method="POST" class="d-inline">
                                @csrf
                                <button type="submit"
                                    class="btn btn-gradient-amber border-0 rounded-pill px-4 py-3 fw-bold text-uppercase tracking-wider fs-7 shadow shadow-amber transition-all"
                                    id="btnBecomeRevisor" data-loading-text="{{ __('ui.processing') }}">
                                    <i class="bi bi-file-earmark-person-fill me-2"></i> {{ __('ui.apply_now') }}
                                </button>
                            </form>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </div>
</section>
