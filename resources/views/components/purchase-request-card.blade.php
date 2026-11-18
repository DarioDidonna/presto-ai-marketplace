@props(['request', 'perspective' => 'buyer', 'conversationLink' => true])
{{-- Una richiesta d'acquisto vista dall'acquirente ("I miei ordini") o dal venditore ("Richieste ricevute") --}}
@php
    $other = $perspective === 'buyer' ? $request->seller : $request->buyer;
    $label = $perspective === 'buyer' ? 'ui.cart_seller' : 'ui.sales_buyer';
    $status = [
        'pending' => ['bg-warning text-dark', 'bi-hourglass-split'],
        'accepted' => ['bg-success', 'bi-check-circle'],
        'declined' => ['bg-danger', 'bi-x-circle'],
        'cancelled' => ['bg-secondary', 'bi-slash-circle'],
    ][$request->status];
@endphp
<article {{ $attributes->merge(['class' => 'p-4 rounded-3 border bg-navbar-card shadow-lg mb-4 '.($request->isPending() && $perspective === 'seller' ? 'border-warning' : 'border-secondary-subtle')]) }}>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div class="d-flex align-items-center gap-2">
            @if ($other)
                <x-avatar :user="$other" :size="32" />
            @endif
            <div>
                <h2 class="h6 text-white fw-bold mb-0">
                    @if ($other && $perspective === 'buyer')
                        <a href="{{ route('sellers.show', $other) }}" class="text-white text-decoration-none">{{ __($label, ['name' => $other->name]) }}</a>
                    @else
                        {{ __($label, ['name' => $other?->name ?? __('ui.anonymous_user')]) }}
                    @endif
                </h2>
                <span class="text-muted-custom fs-8">{{ __('ui.orders_sent_on', ['date' => $request->created_at->translatedFormat('j F Y, H:i')]) }}</span>
            </div>
        </div>
        <span class="badge {{ $status[0] }} fs-7">
            <i class="bi {{ $status[1] }} me-1" aria-hidden="true"></i>{{ __('ui.order_status_'.$request->status) }}
        </span>
    </div>

    @foreach ($request->items as $item)
        <div class="d-flex align-items-center gap-3 py-2 {{ $loop->last ? '' : 'border-bottom border-secondary-subtle' }}">
            <img src="{{ $item->article?->images->first()?->thumbnailUrl() ?? asset('media/placeholder.svg') }}"
                alt="" width="56" height="56" loading="lazy" class="rounded object-fit-cover flex-shrink-0">
            <div class="flex-grow-1 min-w-0">
                @if ($item->article?->is_accepted)
                    <a href="{{ route('articles.show', $item->article) }}" class="text-white fw-semibold text-decoration-none d-block text-truncate">{{ $item->title }}</a>
                @else
                    <span class="text-white fw-semibold d-block text-truncate">{{ $item->title }}</span>
                @endif
            </div>
            <span class="text-neon-cyan fw-bold font-monospace text-nowrap">{{ number_format((float) $item->price, 2, ',', '.') }} €</span>
        </div>
    @endforeach

    @if ($request->message)
        <p class="text-muted-custom fs-7 fst-italic mt-3 mb-0">
            <i class="bi bi-chat-left-quote me-1" aria-hidden="true"></i>{{ $request->message }}
        </p>
    @endif

    {{-- Contatto: il venditore vede sempre l'acquirente; l'acquirente vede il venditore dopo l'accettazione --}}
    @if ($other && ! $other->anonymized_at && ($perspective === 'seller' || $request->status === 'accepted'))
        <p class="fs-7 mt-3 mb-0">
            <i class="bi bi-envelope me-1 text-neon-cyan" aria-hidden="true"></i>
            <a href="mailto:{{ $other->email }}" class="text-neon-cyan">{{ $other->email }}</a>
        </p>
    @endif

    @if ($request->status === 'accepted')
        @if ($request->review)
            {{-- Recensione già scritta: l'acquirente vede la sua, il venditore quella ricevuta --}}
            <div class="review-box mt-3 p-3 rounded-3">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <span class="text-white fw-semibold fs-7">
                        {{ $perspective === 'buyer' ? __('ui.reviews_yours') : __('ui.reviews_from', ['name' => $other?->name ?? __('ui.anonymous_user')]) }}
                    </span>
                    <x-stars :rating="$request->review->rating" />
                </div>
                @if ($request->review->comment)
                    <p class="text-muted-custom fs-7 mb-0 mt-2 review-comment">{{ $request->review->comment }}</p>
                @endif
            </div>
        @elseif ($perspective === 'buyer' && auth()->user()?->can('review', $request))
            @php($bag = $errors->getBag('review'.$request->id))
            <form method="POST" action="{{ route('reviews.store', $request) }}" class="review-box mt-3 p-3 rounded-3">
                @csrf
                <fieldset>
                    <legend class="fs-7 fw-bold text-white mb-2">{{ __('ui.reviews_question', ['name' => $other?->name ?? __('ui.anonymous_user')]) }}</legend>
                    <div class="star-input d-inline-flex gap-1" role="radiogroup" aria-label="{{ __('ui.reviews_rating') }}">
                        @for ($i = 1; $i <= \App\Models\Review::MAX_RATING; $i++)
                            <input type="radio" name="rating" value="{{ $i }}" id="rating-{{ $request->id }}-{{ $i }}" class="visually-hidden" required
                                @checked($bag->any() && (int) old('rating') === $i)>
                            <label for="rating-{{ $request->id }}-{{ $i }}" title="{{ trans_choice('ui.reviews_star_label', $i, ['count' => $i]) }}">
                                <i class="bi bi-star-fill" aria-hidden="true"></i><span class="visually-hidden">{{ trans_choice('ui.reviews_star_label', $i, ['count' => $i]) }}</span>
                            </label>
                        @endfor
                    </div>
                </fieldset>
                @if ($bag->has('rating'))
                    <div class="text-danger fs-8 mt-1">{{ $bag->first('rating') }}</div>
                @endif
                <label for="comment-{{ $request->id }}" class="visually-hidden">{{ __('ui.reviews_comment') }}</label>
                <textarea name="comment" id="comment-{{ $request->id }}" rows="2" maxlength="1000"
                    class="form-control conversation-input fs-7 mt-2" placeholder="{{ __('ui.reviews_comment') }}">{{ $bag->any() ? old('comment') : '' }}</textarea>
                @if ($bag->has('comment'))
                    <div class="text-danger fs-8 mt-1">{{ $bag->first('comment') }}</div>
                @endif
                <div class="d-flex justify-content-end mt-2">
                    <button type="submit" class="btn btn-sm btn-warning fw-semibold">
                        <i class="bi bi-star-fill me-1" aria-hidden="true"></i>{{ __('ui.reviews_submit') }}
                    </button>
                </div>
            </form>
        @endif
    @endif

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border-top border-secondary-subtle pt-3 mt-3">
        <span class="text-muted-custom fs-7">{{ __('ui.'.($perspective === 'buyer' ? 'order' : 'sales').'_status_help_'.$request->status) }}</span>
        <span class="text-white fw-bold font-monospace">{{ __('ui.cart_total') }}: {{ number_format((float) $request->total, 2, ',', '.') }} €</span>
    </div>

    @if ($conversationLink || trim($slot))
        <div class="d-flex flex-wrap justify-content-end gap-2 mt-3">
            @if ($conversationLink)
                <a href="{{ route('conversations.show', $request) }}" class="btn btn-sm btn-outline-neon-cyan">
                    <i class="bi bi-chat-dots me-1" aria-hidden="true"></i>{{ __('ui.messages_open') }}
                    @if ($request->unread_messages_count)
                        <span class="badge bg-info text-dark rounded-pill ms-1">{{ $request->unread_messages_count }}<span class="visually-hidden"> {{ __('ui.messages_unread') }}</span></span>
                    @endif
                </a>
            @endif
            {{ $slot }}
        </div>
    @endif
</article>
