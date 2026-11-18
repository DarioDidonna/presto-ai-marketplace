<x-layout :title="$article->title" :description="Str::limit(Str::squish($article->description), 155)" type="product"
    :image="$article->images->first()?->getDisplayUrl()" :image-alt="$article->title">
    <x-slot:meta>
        <meta property="product:price:amount" content="{{ number_format($article->price, 2, '.', '') }}">
        <meta property="product:price:currency" content="EUR">
        @unless ($article->is_accepted)
            {{-- Annunci non pubblicati (visibili solo ad autore e revisori): fuori dai motori di ricerca --}}
            <meta name="robots" content="noindex">
        @endunless
    </x-slot:meta>

    <div class="article-show-wrapper pt-custom-navbar pb-5">
        <div class="container py-4 position-relative z-1">

            <div class="mb-4">
                <a href="{{ route('articles.index') }}"
                    class="text-neon-cyan text-decoration-none text-uppercase tracking-wider fs-8 fw-bold transition-all hover-back">
                    <i class="bi bi-arrow-left me-2"></i> {{ __('ui.back_to_board') }}
                </a>
            </div>

            @if (session()->has('message'))
                <div class="alert alert-success bg-success bg-opacity-10 border-success text-success d-flex align-items-center mb-4" role="status">
                    <i class="bi bi-check-circle-fill me-2"></i> {{ session('message') }}
                </div>
            @endif

            <div class="row g-5">

                <div class="col-12 col-lg-7">
                    @if ($article->images->count() > 0)
                        <div id="articleCarousel"
                            class="carousel slide border border-neon-cyan shadow-lg rounded-3 overflow-hidden bg-navbar"
                            data-bs-ride="false">

                            @if ($article->images->count() > 1)
                                <div class="carousel-indicators">
                                    @foreach ($article->images as $key => $image)
                                        <button type="button" data-bs-target="#articleCarousel"
                                            data-bs-slide-to="{{ $key }}"
                                            class="{{ $loop->first ? 'active' : '' }}"
                                            aria-current="{{ $loop->first ? 'true' : 'false' }}"
                                            aria-label="{{ __('ui.image_number', ['n' => $key + 1]) }}"></button>
                                    @endforeach
                                </div>
                            @endif

                            <div class="carousel-inner">
                                @foreach ($article->images as $key => $image)
                                    <div class="carousel-item {{ $loop->first ? 'active' : '' }}">
                                        <img src="{{ $image->getDisplayUrl() }}"
                                            class="d-block w-100 img-fluid object-fit-cover"
                                            width="800" height="800" @unless ($loop->first) loading="lazy" @endunless
                                            alt="{{ __('ui.image_number', ['n' => $key + 1]) }} - {{ $article->title }}">
                                    </div>
                                @endforeach
                            </div>

                            @if ($article->images->count() > 1)
                                <button class="carousel-control-prev" type="button" data-bs-target="#articleCarousel"
                                    data-bs-slide="prev">
                                    <span
                                        class="carousel-control-prev-icon bg-cyber-dark p-3 rounded-circle border border-neon-cyan"
                                        aria-hidden="true"></span>
                                    <span class="visually-hidden">{{ __('ui.previous') }}</span>
                                </button>
                                <button class="carousel-control-next" type="button" data-bs-target="#articleCarousel"
                                    data-bs-slide="next">
                                    <span
                                        class="carousel-control-next-icon bg-cyber-dark p-3 rounded-circle border border-neon-cyan"
                                        aria-hidden="true"></span>
                                    <span class="visually-hidden">{{ __('ui.next') }}</span>
                                </button>
                            @endif
                        </div>
                    @else
                        <div
                            class="border border-secondary shadow-lg rounded-3 overflow-hidden bg-navbar text-center p-2">
                            <img src="{{ asset('media/placeholder.svg') }}"
                                class="img-fluid rounded-3 w-100 object-fit-cover opacity-75"
                                alt="{{ __('ui.no_photo_alt') }}" width="300" height="300">
                            <div class="py-2 text-muted-custom font-monospace fs-8">
                                <i class="bi bi-image-alt me-1"></i> {{ __('ui.no_photo_alt') }}
                            </div>
                        </div>
                    @endif
                </div>

                <div class="col-12 col-lg-5 d-flex flex-column justify-content-between">
                    <div
                        class="p-4 rounded-3 border border-secondary-subtle bg-navbar-card shadow-lg h-100 d-flex flex-column">

                        @if ($article->isSold())
                            <div class="alert alert-secondary bg-secondary bg-opacity-25 border-secondary text-light fs-7 py-2 mb-3" role="status">
                                <i class="bi bi-bag-check-fill me-1" aria-hidden="true"></i> {{ __('ui.sold_notice') }}
                            </div>
                        @elseif ($article->isReserved())
                            <div class="alert alert-warning bg-warning bg-opacity-10 border-warning text-warning fs-7 py-2 mb-3" role="status">
                                <i class="bi bi-lock-fill me-1" aria-hidden="true"></i> {{ __('ui.reserved_notice') }}
                            </div>
                        @endif

                        {{-- Visibile solo ad autore e revisori: gli altri ricevono 404 per annunci non pubblicati --}}
                        @if ($article->is_accepted === null)
                            <div class="alert alert-warning bg-warning bg-opacity-10 border-warning text-warning fs-7 py-2 mb-3" role="status">
                                <i class="bi bi-hourglass-split me-1"></i> {{ __('ui.article_pending_notice') }}
                            </div>
                        @elseif ($article->is_accepted === false)
                            <div class="alert alert-danger bg-danger bg-opacity-10 border-danger text-danger fs-7 py-2 mb-3" role="status">
                                <i class="bi bi-x-octagon me-1"></i> {{ __('ui.article_rejected_notice') }}
                            </div>
                        @endif

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            @if ($article->category)
                                <a href="{{ route('categories.show', ['category' => $article->category]) }}"
                                    class="badge bg-transparent border border-neon-amber text-neon-amber text-uppercase tracking-wider fs-9 text-decoration-none transition-all hover-glow-amber">
                                    <i class="bi bi-tag-fill me-1"></i> {{ $article->category?->translated_name ?? __('ui.no_category') }}
                                </a>
                            @else
                                <span
                                    class="badge bg-transparent border border-secondary text-muted fs-9 text-uppercase tracking-wider">{{ __('ui.no_category') }}</span>
                            @endif

                            <span class="text-muted-custom fs-8 font-monospace">
                                <i class="bi bi-calendar3 me-1"></i> {{ $article->created_at->format('d/m/Y') }}
                            </span>
                        </div>

                        <h1 class="h2 fw-black text-white text-uppercase tracking-tight mb-2">{{ $article->title }}</h1>

                        {{-- Venditore: link al profilo pubblico con media delle recensioni --}}
                        <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                            @if ($article->user)
                                <a href="{{ route('sellers.show', $article->user) }}" class="d-inline-flex align-items-center gap-2 fs-7 text-neon-cyan font-monospace text-decoration-none">
                                    <x-avatar :user="$article->user" :size="24" />{{ __('ui.published_by', ['name' => $article->user->name]) }}
                                </a>
                                <x-seller-rating :summary="$article->user->reviewSummary()" />
                            @else
                                <span class="fs-7 text-neon-cyan font-monospace">{{ __('ui.published_by', ['name' => __('ui.anonymous_user')]) }}</span>
                            @endif
                        </div>

                        <div
                            class="py-3 px-4 rounded-3 bg-cyber-dark border-start border-3 border-neon-cyan mb-4 shadow-sm">
                            <span
                                class="text-muted-custom fs-8 text-uppercase tracking-wider d-block mb-1">{{ __('ui.requested_price') }}</span>
                            <span class="display-6 fw-bold text-glow text-neon-cyan font-monospace">
                                {{ number_format($article->price, 2, ',', '.') }} €
                            </span>
                        </div>

                        <div class="flex-grow-1 mb-4">
                            <h6 class="text-white text-uppercase tracking-wide fs-7 fw-bold mb-2">
                                <i class="bi bi-file-text me-2 text-neon-cyan"></i>{{ __('ui.article_description') }}
                            </h6>
                            <p class="text-muted-custom fs-6 lh-base text-pre-wrap">{{ $article->description }}</p>
                        </div>

                        <div class="pt-4 border-top border-secondary-subtle mt-auto">
                            @guest
                                <a href="{{ route('login') }}"
                                    class="btn btn-cyber-gradient w-100 text-uppercase text-light tracking-wider fs-7 fw-bold">
                                    <i class="bi bi-box-arrow-in-right me-2"></i> {{ __('ui.login_to_contact') }}
                                </a>
                            @else
                                @if (Auth::user()->can('update', $article))
                                    <p class="text-muted-custom fs-8 mb-2 text-center">
                                        <i class="bi bi-person-check me-1 text-neon-cyan"></i> {{ __('ui.your_own_ad') }}
                                    </p>
                                    <div class="row g-2">
                                        <div class="col-12 col-md-7">
                                            <a href="{{ route('articles.edit', $article) }}"
                                                class="btn btn-cyber-gradient w-100 text-uppercase text-light tracking-wider fs-7 fw-bold">
                                                <i class="bi bi-pencil-square me-2"></i>{{ __('ui.edit_ad') }}
                                            </a>
                                        </div>
                                        <div class="col-12 col-md-5">
                                            <button type="button" class="btn btn-outline-danger w-100 text-uppercase tracking-wider fs-7 fw-bold"
                                                data-bs-toggle="modal" data-bs-target="#confirmDeleteModal">
                                                <i class="bi bi-trash me-2"></i>{{ __('ui.delete') }}
                                            </button>
                                        </div>
                                    </div>
                                @elseif ($article->user)
                                    {{-- Carrello: solo annunci pubblicati (ArticlePolicy::addToCart) --}}
                                    @can('addToCart', $article)
                                        @if ($inCart)
                                            <form action="{{ route('cart.remove', $article) }}" method="POST" class="mb-2">
                                                @csrf
                                                @method('DELETE')
                                                <div class="d-flex gap-2">
                                                    <a href="{{ route('cart.index') }}" class="btn btn-success flex-grow-1 text-uppercase tracking-wider fs-7 fw-bold">
                                                        <i class="bi bi-bag-check-fill me-2" aria-hidden="true"></i>{{ __('ui.cart_in') }}
                                                    </a>
                                                    <button type="submit" class="btn btn-outline-danger" title="{{ __('ui.cart_remove') }}" aria-label="{{ __('ui.cart_remove') }}">
                                                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                                                    </button>
                                                </div>
                                            </form>
                                        @else
                                            <form action="{{ route('cart.add', $article) }}" method="POST" class="mb-2">
                                                @csrf
                                                <button type="submit" class="btn btn-warning w-100 text-uppercase tracking-wider fs-7 fw-bold text-dark">
                                                    <i class="bi bi-bag-plus-fill me-2" aria-hidden="true"></i>{{ __('ui.cart_add') }}
                                                </button>
                                            </form>
                                        @endif
                                    @endcan
                                @endif
                            @endguest
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </div>

    @can('delete', $article)
        <div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-labelledby="confirmDeleteModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content bg-dark border border-danger shadow-lg">
                    <div class="modal-header border-secondary">
                        <h2 class="modal-title fs-5 text-white fw-bold" id="confirmDeleteModalLabel">
                            <i class="bi bi-trash-fill text-danger me-2"></i>{{ __('ui.confirm_delete_title') }}
                        </h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('ui.close') }}"></button>
                    </div>
                    <div class="modal-body text-muted-custom">
                        {{ __('ui.confirm_delete_text', ['title' => $article->title]) }}
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-outline-secondary text-white" data-bs-dismiss="modal">{{ __('ui.cancel') }}</button>
                        <form action="{{ route('articles.destroy', $article) }}" method="POST" class="m-0">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger fw-bold">{{ __('ui.confirm_delete_button') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endcan

</x-layout>
