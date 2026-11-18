<x-layout :title="__('ui.orders_title')">

    <div class="position-relative overflow-hidden bg-navbar py-5 pt-custom-navbar border-bottom border-secondary-subtle">
        <div class="container position-relative z-1 py-4 text-center">
            <span class="badge bg-transparent border border-neon-cyan text-neon-cyan text-uppercase tracking-widest px-3 py-2 mb-3 fs-8 fw-semibold">
                <i class="bi bi-receipt me-1" aria-hidden="true"></i>{{ __('ui.orders_badge') }}
            </span>
            <h1 class="display-5 fw-black text-white text-uppercase tracking-tight mb-0">{{ __('ui.orders_title') }}</h1>
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

            @forelse ($orders as $order)
                <x-purchase-request-card :request="$order" perspective="buyer">
                    @can('cancel', $order)
                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelOrder{{ $order->id }}">
                            <i class="bi bi-x-circle me-1" aria-hidden="true"></i>{{ __('ui.orders_cancel') }}
                        </button>
                    @endcan
                </x-purchase-request-card>

                @can('cancel', $order)
                    <x-confirm-modal :id="'cancelOrder'.$order->id" :title="__('ui.orders_cancel_title')"
                        :text="__('ui.orders_cancel_text')" :action="route('orders.cancel', $order)" :confirm="__('ui.orders_cancel_confirm')" />
                @endcan
            @empty
                <div class="row justify-content-center py-5">
                    <div class="col-12 col-md-6 text-center py-5 border border-dashed border-secondary rounded-4 bg-navbar-dark shadow">
                        <div class="fs-1 mb-3"><i class="bi bi-receipt text-neon-cyan" aria-hidden="true"></i></div>
                        <h2 class="h4 text-white fw-bold text-uppercase tracking-wide mb-2">{{ __('ui.orders_empty') }}</h2>
                        <p class="text-muted-custom fs-7 mb-4">{{ __('ui.orders_empty_text') }}</p>
                        <a href="{{ route('articles.index') }}" class="btn btn-outline-neon-cyan text-uppercase tracking-wider fs-7 fw-bold px-4 py-2">
                            <i class="bi bi-search me-2" aria-hidden="true"></i>{{ __('ui.cart_continue') }}
                        </a>
                    </div>
                </div>
            @endforelse

        </div>
    </div>

</x-layout>
