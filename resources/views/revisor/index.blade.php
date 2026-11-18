<x-layout :title="__('ui.revisor_dashboard_title')">
    <div class="container-fluid pt-custom-navbar pb-5">

        <div class="row my-4 justify-content-center">
            <div class="col-12 col-md-6 col-lg-4">
                <div class="rounded shadow-lg bg-dark p-3 text-center border border-secondary">
                    <h1 class="display-5 m-0 text-uppercase fw-bold tracking-wide text-white fs-3">
                        <i class="bi bi-shield-check me-2 text-neon-cyan animate-pulse"></i>{{ __('ui.revisor_dashboard_title') }}
                    </h1>
                </div>
            </div>
        </div>

        {{-- Esito dell'ultima azione, sempre visibile (anche quando la coda è vuota), con "Annulla" entro la finestra --}}
        @php
            $lastReview = session('last_review');
            $undoSeconds = $lastReview
                ? max(0, $lastReview['decided_at'] + \App\Http\Controllers\RevisorController::UNDO_MINUTES * 60 - now()->getTimestamp())
                : 0;
        @endphp
        @if (session()->has('message') || session()->has('errorMessage') || $undoSeconds > 0)
            <div class="row justify-content-center px-xl-5 mb-3">
                <div class="col-12 col-xl-10">
                    @if (session()->has('errorMessage'))
                        <div class="alert alert-warning bg-warning bg-opacity-10 border-warning text-warning d-flex align-items-center gap-2 mb-0" role="status">
                            <i class="bi bi-exclamation-triangle-fill"></i> {{ session('errorMessage') }}
                        </div>
                    @else
                        <div class="alert bg-success bg-opacity-10 border-success text-white d-flex flex-wrap align-items-center justify-content-between gap-2 mb-0" role="status">
                            <span>
                                <i class="bi bi-check-circle-fill text-success me-2"></i>{{ session('message') ?? __('ui.undo_available') }}
                            </span>
                            @if ($undoSeconds > 0)
                                <form action="{{ route('revisor.undo') }}" method="POST" class="d-flex align-items-center gap-2 m-0">
                                    @csrf
                                    @method('PATCH')
                                    <span class="text-muted-custom fs-8">{{ __('ui.undo_within', ['minutes' => (int) ceil($undoSeconds / 60)]) }}</span>
                                    <button type="submit" class="btn btn-sm btn-outline-light fw-bold">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i>{{ __('ui.undo') }}
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @if ($article_to_check)
            <div class="row justify-content-center pt-3 px-xl-5">

                <div class="col-12 col-lg-7 mb-4 mb-lg-0">
                    @if ($article_to_check->images->count())
                        <div class="card bg-dark border border-secondary shadow-lg rounded p-3">

                            <div id="revisorImageCarousel" class="carousel slide" data-bs-ride="false">
                                <div class="carousel-inner rounded border border-secondary bg-black">
                                    @foreach ($article_to_check->images as $key => $image)
                                        <div class="carousel-item {{ $key === 0 ? 'active' : '' }}">
                                            <div class="position-relative text-center bg-black">
                                                <img src="{{ $image->getDisplayUrl() }}"
                                                    class="img-fluid object-fit-contain"
                                                    alt="{{ __('ui.image_number', ['n' => $key + 1]) }}">

                                                <span
                                                    class="position-absolute top-0 start-0 m-3 badge bg-dark bg-opacity-75 border border-secondary text-neon-cyan font-monospace">
                                                    {{ $key + 1 }} / {{ $article_to_check->images->count() }}
                                                </span>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                @if ($article_to_check->images->count() > 1)
                                    <button class="carousel-control-prev" type="button"
                                        data-bs-target="#revisorImageCarousel" data-bs-slide="prev">
                                        <span
                                            class="carousel-control-prev-icon p-3 bg-dark bg-opacity-50 rounded-circle border border-secondary"
                                            aria-hidden="true"></span>
                                        <span class="visually-hidden">{{ __('ui.previous') }}</span>
                                    </button>
                                    <button class="carousel-control-next" type="button"
                                        data-bs-target="#revisorImageCarousel" data-bs-slide="next">
                                        <span
                                            class="carousel-control-next-icon p-3 bg-dark bg-opacity-50 rounded-circle border border-secondary"
                                            aria-hidden="true"></span>
                                        <span class="visually-hidden">{{ __('ui.next') }}</span>
                                    </button>
                                @endif
                            </div>

                            @if ($article_to_check->images->count() > 1)
                                <div class="row g-2 mt-3 justify-content-center">
                                    @foreach ($article_to_check->images as $key => $image)
                                        <div class="col-2">
                                            <button type="button" data-bs-target="#revisorImageCarousel"
                                                data-bs-slide-to="{{ $key }}"
                                                class="btn p-0 w-100 rounded overflow-hidden border {{ $key === 0 ? 'border-neon-cyan' : 'border-secondary' }} opacity-75 hover-opacity-100"
                                                aria-current="{{ $key === 0 ? 'true' : 'false' }}"
                                                aria-label="{{ __('ui.image_number', ['n' => $key + 1]) }}">
                                                <img src="{{ $image->thumbnailUrl() }}"
                                                    class="img-fluid object-fit-cover" alt="" loading="lazy">
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                        </div>

                        <div class="card bg-dark border border-secondary shadow-lg rounded p-3 mt-4">
                            <h5 class="text-neon-cyan font-monospace text-uppercase small mb-3">
                                <i class="bi bi-person-bounding-box me-2"></i>{{ __('ui.vision_title') }}
                            </h5>

                            {{-- Oscuramento dei volti (rilevatore locale): il revisore controlla che sia giusto --}}
                            <ul class="list-unstyled mb-0 d-flex flex-column gap-2">
                                @foreach ($article_to_check->images as $key => $image)
                                    <li class="d-flex align-items-start gap-2 p-2 bg-black rounded border border-secondary">
                                        <span class="badge bg-secondary font-monospace flex-shrink-0">{{ __('ui.image_number', ['n' => $key + 1]) }}</span>
                                        <span class="small {{ $image->facesChecked() ? 'text-neon-cyan' : 'text-warning' }}">
                                            @if ($image->facesChecked())
                                                <i class="bi bi-person-bounding-box me-1" aria-hidden="true"></i>{{ trans_choice('ui.faces_hidden', $image->faces_count, ['count' => $image->faces_count]) }}
                                            @else
                                                <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>{{ __('ui.faces_unchecked') }}
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @else
                        <div class="card bg-dark border border-secondary shadow-lg rounded p-5 text-center d-flex flex-column align-items-center justify-content-center">
                            <i class="bi bi-image text-muted display-2 mb-3"></i>
                            <p class="text-muted-custom fst-italic">{{ __('ui.no_images_uploaded') }}</p>
                        </div>
                    @endif
                </div>

                <div class="col-12 col-lg-5 ps-lg-4 d-flex flex-column justify-content-between">
                    <div class="card bg-dark border border-secondary shadow-lg rounded p-4 h-100 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <span class="badge bg-black text-neon-cyan border border-secondary text-uppercase font-monospace tracking-wide px-3 py-2">
                                    <i class="bi bi-hourglass-split me-1 animate-pulse"></i>{{ __('ui.review_phase') }}
                                </span>
                                <h4 class="text-neon-cyan fw-bold font-monospace m-0 fs-3">
                                    {{ number_format($article_to_check->price, 2, ',', '.') }} €
                                </h4>
                            </div>

                            <h1 class="text-white fw-bold mb-2 display-6">{{ $article_to_check->title }}</h1>

                            <div class="d-flex gap-3 mb-4 text-muted-custom fs-6 font-monospace">
                                <span><i class="bi bi-person me-1"></i>{{ $article_to_check->user?->name ?? __('ui.anonymous_user') }}</span>
                                <span><i class="bi bi-tag me-1"></i>#{{ $article_to_check->category?->translated_name }}</span>
                            </div>

                            <hr class="border-secondary my-3">

                            <div class="p-3 bg-black rounded border border-secondary">
                                <label class="text-neon-cyan font-monospace small mb-2 d-block text-uppercase">{{ __('ui.product_description') }}</label>
                                <p class="text-light mb-0 lh-lg text-pre-wrap">
                                    {{ $article_to_check->description }}
                                </p>
                            </div>

                            {{-- Parere dell'AI (solo se l'agente è configurato): non cambia lo stato dell'annuncio --}}
                            @if (app(\App\Services\AiAgentClient::class)->isEnabled())
                                <livewire:review-assistant :article-id="$article_to_check->id" :key="'review-'.$article_to_check->id" />
                            @endif
                        </div>

                        <div class="mt-4">
                            {{-- I pulsanti aprono una finestra di conferma: l'invio vero parte solo dal form nella modale --}}
                            <div class="d-flex justify-content-between gap-3">
                                <button type="button" class="btn btn-outline-danger py-2.5 w-100 fw-bold shadow-sm border-2"
                                    data-bs-toggle="modal" data-bs-target="#confirmRejectModal">
                                    <i class="bi bi-x-circle me-2"></i>{{ __('ui.reject') }}
                                </button>

                                <button type="button" class="btn btn-success py-2.5 w-100 fw-bold shadow-sm text-black fw-black"
                                    data-bs-toggle="modal" data-bs-target="#confirmAcceptModal">
                                    <i class="bi bi-check-circle me-2"></i>{{ __('ui.accept') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            @foreach ([
                ['id' => 'confirmAcceptModal', 'route' => 'revisor.accept', 'icon' => 'bi-check-circle-fill text-success', 'border' => 'border-success', 'button' => 'btn-success text-black', 'key' => 'accept'],
                ['id' => 'confirmRejectModal', 'route' => 'revisor.reject', 'icon' => 'bi-x-octagon-fill text-danger', 'border' => 'border-danger', 'button' => 'btn-danger', 'key' => 'reject'],
            ] as $modal)
                <div class="modal fade" id="{{ $modal['id'] }}" tabindex="-1" aria-labelledby="{{ $modal['id'] }}Label" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content bg-dark border {{ $modal['border'] }} shadow-lg">
                            <div class="modal-header border-secondary">
                                <h2 class="modal-title fs-5 text-white fw-bold" id="{{ $modal['id'] }}Label">
                                    <i class="bi {{ $modal['icon'] }} me-2"></i>{{ __('ui.confirm_' . $modal['key'] . '_title') }}
                                </h2>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('ui.close') }}"></button>
                            </div>
                            <div class="modal-body text-muted-custom">
                                {{ __('ui.confirm_' . $modal['key'] . '_text', ['title' => $article_to_check->title]) }}
                            </div>
                            <div class="modal-footer border-secondary">
                                <button type="button" class="btn btn-outline-secondary text-white" data-bs-dismiss="modal">
                                    {{ __('ui.cancel') }}
                                </button>
                                <form action="{{ route($modal['route'], ['article' => $article_to_check]) }}" method="POST" class="m-0">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn {{ $modal['button'] }} fw-bold">
                                        {{ __('ui.confirm_' . $modal['key'] . '_button') }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        @else
            <div class="row justify-content-center align-items-center text-center py-5">
                <div class="col-12 col-md-6 col-lg-5 mt-4">
                    <div class="p-5 rounded bg-dark border border-secondary shadow-lg">
                        <div class="mb-4">
                            <i class="bi bi-clipboard-check text-neon-cyan display-1 animate-pulse"></i>
                        </div>
                        <h1 class="fst-italic display-6 text-white mb-3">{{ __('ui.great_job') }}</h1>
                        <p class="text-muted-custom mb-4">{{ __('ui.no_new_articles') }}</p>
                        <a href="{{ route('homepage') }}" class="btn btn-outline-info px-4 py-2 fw-bold shadow">
                            <i class="bi bi-house-door me-2"></i>{{ __('ui.go_homepage') }}
                        </a>
                    </div>
                </div>
            </div>
        @endif

    </div>
</x-layout>