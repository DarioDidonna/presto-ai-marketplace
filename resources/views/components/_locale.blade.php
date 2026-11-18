
<form action="{{ route('locale.update', $lang) }}" method="POST" class="d-inline">
    @csrf
    <button type="submit" class="btn p-0 border-0 bg-transparent nav-link d-inline">
        <img src="{{ asset('vendor/blade-flags/country-' . ($lang === 'en' ? 'gb' : $lang) . '.svg') }}" width="32" height="32"
            alt="{{ __('ui.language') }} {{ strtoupper($lang) }}" class="rounded shadow-sm flag-hover">
    </button>
</form>


