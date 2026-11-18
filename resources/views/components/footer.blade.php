{{-- Footer: marchio e inviti, link, stato reale dei servizi (FooterStatusComposer), crediti --}}
@php($active = collect($services)->where('state', 'on')->count())
@php($leds = ['on' => 'footer-led-on', 'off' => 'footer-led-off', 'idle' => 'footer-led-idle', 'disabled' => 'footer-led-disabled'])
<footer class="bg-footer-dark border-top-alpha text-muted-custom pt-5 pb-3 mt-auto footer-glow" data-footer-glow>
    <div class="container position-relative">
        <div class="row gy-5 gx-lg-5">

            <div class="col-12 col-lg-3">
                <a class="d-inline-flex align-items-center gap-2 text-decoration-none mb-3" href="{{ route('homepage') }}">
                    <i class="bi bi-layers-half text-neon-cyan fs-4" aria-hidden="true"></i>
                    <span class="fw-bold tracking-wider text-uppercase text-white text-glow fs-5">PRESTO<span class="text-neon-cyan">.</span>IT</span>
                </a>
                <p class="fs-7 lh-base mb-4 max-w-540">{{ __('ui.footer_description') }}</p>
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('articles.create') }}" class="btn btn-cyber-gradient btn-sm text-uppercase tracking-wider fs-8 fw-bold text-light">
                        <i class="bi bi-plus-circle-fill me-1" aria-hidden="true"></i>{{ __('ui.publish_ad') }}
                    </a>
                    @if (Auth::user()?->is_revisor)
                        <a href="{{ route('revisor.index') }}" class="btn btn-outline-custom btn-sm text-uppercase tracking-wider fs-8 fw-bold text-light">
                            <i class="bi bi-shield-lock-fill text-neon-amber me-1" aria-hidden="true"></i>{{ __('ui.revisor_area') }}
                        </a>
                    @else
                        <a href="{{ route('homepage') }}#revisorCtaSection" class="btn btn-outline-custom btn-sm text-uppercase tracking-wider fs-8 fw-bold text-light">
                            <i class="bi bi-shield-check text-neon-amber me-1" aria-hidden="true"></i>{{ __('ui.footer_become_revisor') }}
                        </a>
                    @endif
                </div>
            </div>

            <nav class="col-6 col-md-4 col-lg-2" aria-labelledby="footer-explore">
                <h2 id="footer-explore" class="footer-heading">{{ __('ui.footer_explore') }}</h2>
                <ul class="list-unstyled d-flex flex-column gap-2 fs-7 mb-0">
                    <li><a href="{{ route('articles.index') }}" class="footer-link-custom">{{ __('ui.announcements') }}</a></li>
                    <li><a href="{{ route('homepage') }}#categoriesSection" class="footer-link-custom">{{ __('ui.categories') }}</a></li>
                    <li><a href="{{ route('homepage') }}#howItWorksSection" class="footer-link-custom">{{ __('ui.how_it_works') }}</a></li>
                </ul>
            </nav>

            <nav class="col-6 col-md-4 col-lg-2" aria-labelledby="footer-sell">
                <h2 id="footer-sell" class="footer-heading">{{ __('ui.footer_sell') }}</h2>
                <ul class="list-unstyled d-flex flex-column gap-2 fs-7 mb-0">
                    <li><a href="{{ route('articles.create') }}" class="footer-link-custom">{{ __('ui.new_ad') }}</a></li>
                    <li><a href="{{ route('sales.index') }}" class="footer-link-custom">{{ __('ui.sales_title') }}</a></li>
                    <li><a href="{{ route('profile.show') }}" class="footer-link-custom">{{ __('ui.footer_my_ads') }}</a></li>
                </ul>
            </nav>

            <nav class="col-6 col-md-4 col-lg-2" aria-labelledby="footer-account">
                <h2 id="footer-account" class="footer-heading">{{ __('ui.footer_account') }}</h2>
                <ul class="list-unstyled d-flex flex-column gap-2 fs-7 mb-0">
                    @auth
                        <li><a href="{{ route('cart.index') }}" class="footer-link-custom">{{ __('ui.cart_title') }}</a></li>
                        <li><a href="{{ route('orders.index') }}" class="footer-link-custom">{{ __('ui.orders_title') }}</a></li>
                        <li><a href="{{ route('profile.show') }}" class="footer-link-custom">{{ __('ui.my_profile') }}</a></li>
                    @else
                        <li><a href="{{ route('login') }}" class="footer-link-custom">{{ __('ui.login') }}</a></li>
                        <li><a href="{{ route('register') }}" class="footer-link-custom">{{ __('ui.register') }}</a></li>
                    @endauth
                </ul>
            </nav>

            {{-- Stato dei servizi: valori veri, aggiornati al massimo ogni minuto --}}
            <section class="col-12 col-md-6 col-lg-3" aria-labelledby="footer-status-title">
                <div class="footer-status p-3 rounded-3 h-100">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                        <h2 id="footer-status-title" class="footer-heading mb-0">{{ __('ui.footer_status_title') }}</h2>
                        <span class="font-monospace fs-8 {{ $active === count($services) ? 'text-success' : 'text-warning' }}">
                            {{ $active === count($services) ? __('ui.footer_status_all_on') : __('ui.footer_status_partial', ['on' => $active, 'total' => count($services)]) }}
                        </span>
                    </div>
                    <ul class="list-unstyled d-flex flex-column gap-2 mb-0">
                        @foreach ($services as $service)
                            <li class="d-flex align-items-center gap-2 fs-7">
                                <span class="footer-led {{ $leds[$service['state']] }}" aria-hidden="true"></span>
                                <span class="text-white flex-grow-1">{{ __('ui.footer_service_'.$service['key']) }}</span>
                                <span class="font-monospace fs-8 footer-service-value text-end">{{ $service['value'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>
        </div>

        <hr class="border-secondary-subtle my-4">

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 fs-8">
            <span>&copy; {{ date('Y') }} <span class="text-white fw-semibold">Dario Didonna</span> · {{ __('ui.footer_license') }} · {{ __('ui.footer_photo_credits') }}</span>
            <span class="d-flex flex-wrap gap-2" aria-label="{{ __('ui.footer_stack') }}">
                @foreach (['Laravel 13', 'Livewire 4', 'Reverb', 'LangGraph', 'Ollama', 'OpenCV'] as $tech)
                    <span class="footer-chip font-monospace">{{ $tech }}</span>
                @endforeach
            </span>
        </div>
    </div>
</footer>
