<x-layout :title="__('ui.cart_title')">

    <div class="position-relative overflow-hidden bg-navbar py-5 pt-custom-navbar border-bottom border-secondary-subtle">
        <div class="container position-relative z-1 py-4 text-center">
            <span class="badge bg-transparent border border-neon-cyan text-neon-cyan text-uppercase tracking-widest px-3 py-2 mb-3 fs-8 fw-semibold">
                <i class="bi bi-bag me-1" aria-hidden="true"></i>{{ trans_choice('ui.cart_count', $count, ['count' => $count]) }}
            </span>
            <h1 class="display-5 fw-black text-white text-uppercase tracking-tight mb-0">{{ __('ui.cart_title') }}</h1>
        </div>
    </div>

    <div class="py-5">
        <div class="container">

            @if (session()->has('message'))
                <div class="alert alert-success bg-success bg-opacity-10 border-success text-success d-flex align-items-center mb-4" role="status">
                    <i class="bi bi-check-circle-fill me-2" aria-hidden="true"></i> {{ session('message') }}
                </div>
            @endif
            @if (session()->has('warning'))
                <div class="alert alert-warning bg-warning bg-opacity-10 border-warning text-warning d-flex align-items-center mb-4" role="status">
                    <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i> {{ session('warning') }}
                </div>
            @endif

            @if ($groups->isEmpty())
                <div class="row justify-content-center py-5">
                    <div class="col-12 col-md-6 text-center py-5 border border-dashed border-secondary rounded-4 bg-navbar-dark shadow">
                        <div class="fs-1 mb-3"><i class="bi bi-bag text-neon-cyan" aria-hidden="true"></i></div>
                        <h2 class="h4 text-white fw-bold text-uppercase tracking-wide mb-2">{{ __('ui.cart_empty') }}</h2>
                        <p class="text-muted-custom fs-7 mb-4">{{ __('ui.cart_empty_text') }}</p>
                        <a href="{{ route('articles.index') }}" class="btn btn-outline-neon-cyan text-uppercase tracking-wider fs-7 fw-bold px-4 py-2">
                            <i class="bi bi-search me-2" aria-hidden="true"></i>{{ __('ui.cart_continue') }}
                        </a>
                    </div>
                </div>
            @else
                <div class="row g-4">
                    <div class="col-12 col-lg-8 d-flex flex-column gap-4">
                        @foreach ($groups as $group)
                            <section class="p-4 rounded-3 border border-secondary-subtle bg-navbar-card shadow-lg"
                                aria-label="{{ __('ui.cart_seller', ['name' => $group['seller']?->name ?? __('ui.anonymous_user')]) }}">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    @if ($group['seller'])
                                        <x-avatar :user="$group['seller']" :size="32" />
                                    @endif
                                    <h2 class="h6 text-white fw-bold mb-0">
                                        {{ __('ui.cart_seller', ['name' => $group['seller']?->name ?? __('ui.anonymous_user')]) }}
                                    </h2>
                                </div>

                                @foreach ($group['articles'] as $article)
                                    <div class="d-flex align-items-center gap-3 py-3 {{ $loop->last ? '' : 'border-bottom border-secondary-subtle' }}">
                                        <a href="{{ route('articles.show', $article) }}" class="flex-shrink-0">
                                            <img src="{{ $article->images->first()?->thumbnailUrl() ?? asset('media/placeholder.svg') }}"
                                                alt="" width="72" height="72" loading="lazy" class="rounded object-fit-cover">
                                        </a>
                                        <div class="flex-grow-1 min-w-0">
                                            <a href="{{ route('articles.show', $article) }}" class="text-white fw-semibold text-decoration-none d-block text-truncate">
                                                {{ $article->title }}
                                            </a>
                                            <span class="text-muted-custom fs-8">{{ $article->category?->translated_name ?? __('ui.no_category') }}</span>
                                        </div>
                                        <span class="text-neon-cyan fw-bold font-monospace text-nowrap">{{ number_format($article->price, 2, ',', '.') }} €</span>
                                        <form action="{{ route('cart.remove', $article) }}" method="POST" class="m-0">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="{{ __('ui.cart_remove') }}"
                                                aria-label="{{ __('ui.cart_remove') }}: {{ $article->title }}">
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </div>
                                @endforeach

                                <div class="d-flex justify-content-between pt-3 text-muted-custom fs-7">
                                    <span>{{ __('ui.cart_subtotal') }}</span>
                                    <span class="text-white fw-semibold font-monospace">{{ number_format($group['subtotal'], 2, ',', '.') }} €</span>
                                </div>
                            </section>
                        @endforeach
                    </div>

                    <div class="col-12 col-lg-4">
                        <aside class="p-4 rounded-3 border border-neon-cyan bg-navbar-card shadow-lg position-sticky cart-summary">
                            <h2 class="h6 text-white text-uppercase tracking-wide fw-bold mb-3">{{ __('ui.cart_summary') }}</h2>
                            <div class="d-flex justify-content-between text-muted-custom fs-7 mb-2">
                                <span>{{ trans_choice('ui.cart_count', $count, ['count' => $count]) }}</span>
                                <span>{{ trans_choice('ui.cart_sellers', $groups->count(), ['count' => $groups->count()]) }}</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-baseline border-top border-secondary-subtle pt-3 mb-3">
                                <span class="text-white fw-bold">{{ __('ui.cart_total') }}</span>
                                <span class="fs-3 fw-bold text-neon-cyan font-monospace">{{ number_format($total, 2, ',', '.') }} €</span>
                            </div>
                            <p class="text-muted-custom fs-8 mb-3">{{ __('ui.cart_note') }}</p>

                            <form action="{{ route('cart.checkout') }}" method="POST" class="mb-3">
                                @csrf
                                <label for="checkoutMessage" class="form-label text-muted-custom fs-8 text-uppercase tracking-wide fw-semibold">
                                    {{ __('ui.checkout_message_label') }}
                                </label>
                                <textarea name="message" id="checkoutMessage" rows="3" maxlength="500"
                                    placeholder="{{ __('ui.checkout_message_placeholder') }}"
                                    class="form-control bg-dark border-secondary text-white mb-2 @error('message') is-invalid @enderror">{{ old('message') }}</textarea>
                                @error('message')
                                    <div class="invalid-feedback d-block fs-8 mb-2">{{ $message }}</div>
                                @enderror
                                <button type="submit" class="btn btn-cyber-gradient w-100 text-uppercase tracking-wider fs-7 fw-bold">
                                    <i class="bi bi-send-check me-2" aria-hidden="true"></i>{{ __('ui.checkout_submit') }}
                                </button>
                            </form>

                            <a href="{{ route('articles.index') }}" class="btn btn-outline-neon-cyan w-100 mb-2">
                                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>{{ __('ui.cart_continue') }}
                            </a>
                            <form action="{{ route('cart.clear') }}" method="POST" class="m-0">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-link btn-sm text-danger w-100 text-decoration-none">
                                    <i class="bi bi-trash me-1" aria-hidden="true"></i>{{ __('ui.cart_clear') }}
                                </button>
                            </form>
                        </aside>
                    </div>
                </div>
            @endif

        </div>
    </div>

</x-layout>
