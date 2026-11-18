<x-layout :title="__('ui.my_profile')">

    <div class="position-relative overflow-hidden bg-navbar py-5 pt-custom-navbar border-bottom border-secondary-subtle">
        <div class="container position-relative z-1 py-4 text-center">
            <span
                class="badge bg-transparent border border-neon-cyan text-neon-cyan text-uppercase tracking-widest px-3 py-2 mb-3 fs-8 fw-semibold">
                {{ __('ui.account') }}
            </span>
            <h1 class="display-5 fw-black text-white text-uppercase tracking-tight mb-0">
                {{ __('ui.my_profile') }}
            </h1>
        </div>
    </div>

    <div class="py-5">
        <div class="container">

            @if (session()->has('message'))
                <div class="alert alert-success bg-success bg-opacity-10 border-success text-success d-flex align-items-center mb-4" role="status">
                    <i class="bi bi-check-circle-fill me-2"></i> {{ session('message') }}
                </div>
            @endif

            <div class="row g-4">

                <div class="col-12 col-lg-4 d-flex flex-column gap-4">

                    {{-- Foto profilo --}}
                    <div class="p-4 rounded-3 border border-secondary-subtle bg-navbar-card shadow-lg text-center">
                        <x-avatar :user="$user" :size="128" class="mb-3" id="profileAvatar" />

                        <h2 class="h4 text-white fw-bold mb-1 text-break">{{ $user->name }}</h2>
                        <p class="mb-4">
                            @if ($user->is_revisor)
                                <span class="badge bg-warning bg-opacity-10 text-neon-amber border border-warning">
                                    <i class="bi bi-shield-check me-1"></i>{{ __('ui.role_revisor') }}
                                </span>
                            @else
                                <span class="badge bg-transparent text-neon-cyan border border-neon-cyan">
                                    <i class="bi bi-person me-1"></i>{{ __('ui.role_user') }}
                                </span>
                            @endif
                        </p>

                        <form action="{{ route('profile.avatar.update') }}" method="POST" enctype="multipart/form-data" class="text-start">
                            @csrf
                            <label for="avatar" class="form-label text-uppercase tracking-wide fs-8 fw-semibold text-neon-cyan">
                                {{ $user->avatar ? __('ui.avatar_change') : __('ui.avatar_upload') }}
                            </label>
                            <input type="file" name="avatar" id="avatar" accept="image/jpeg,image/png,image/webp" required
                                data-avatar-preview="profileAvatar"
                                class="form-control bg-dark border-secondary text-white @error('avatar') is-invalid @enderror"
                                aria-describedby="avatarHelp">
                            @error('avatar')
                                <div class="invalid-feedback font-monospace fs-8">{{ $message }}</div>
                            @enderror
                            <div id="avatarHelp" class="form-text text-muted-custom fs-8">{{ __('ui.avatar_help') }}</div>

                            <button type="submit" class="btn btn-cyber-gradient w-100 mt-3 text-uppercase tracking-wider fs-7 fw-bold">
                                <i class="bi bi-upload me-2"></i>{{ __('ui.avatar_save') }}
                            </button>
                        </form>

                        @if ($user->avatar)
                            <form action="{{ route('profile.avatar.destroy') }}" method="POST" class="mt-2">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                                    <i class="bi bi-trash me-1"></i>{{ __('ui.avatar_remove') }}
                                </button>
                            </form>
                        @endif
                    </div>

                    {{-- Azioni rapide --}}
                    <div class="p-4 rounded-3 border border-secondary-subtle bg-navbar-card shadow-lg">
                        <h2 class="h6 text-white text-uppercase tracking-wide fw-bold mb-3">
                            <i class="bi bi-lightning-charge me-2 text-neon-cyan"></i>{{ __('ui.quick_actions') }}
                        </h2>
                        <div class="d-grid gap-2">
                            <a href="{{ route('articles.create') }}" class="btn btn-outline-neon-cyan btn-sm text-start">
                                <i class="bi bi-plus-circle me-2"></i>{{ __('ui.publish_ad') }}
                            </a>
                            <a href="{{ route('articles.index') }}" class="btn btn-outline-secondary btn-sm text-start text-white">
                                <i class="bi bi-grid me-2"></i>{{ __('ui.all_ads_title') }}
                            </a>
                            @if ($user->is_revisor)
                                <a href="{{ route('revisor.index') }}" class="btn btn-outline-warning btn-sm text-start">
                                    <i class="bi bi-shield-check me-2"></i>{{ __('ui.revisor_dashboard_title') }}
                                    <span class="badge bg-warning text-dark ms-1">{{ \App\Models\Article::toBeRevisedCount() }}</span>
                                </a>
                            @else
                                <form action="{{ route('revisor.apply') }}" method="POST" class="d-grid">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-warning btn-sm text-start">
                                        <i class="bi bi-shield-plus me-2"></i>{{ __('ui.apply_revisor') }}
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>

                    {{-- Cambio password (azione Fortify già attiva: PUT /user/password) --}}
                    <div class="p-4 rounded-3 border border-secondary-subtle bg-navbar-card shadow-lg" id="password">
                        <h2 class="h6 text-white text-uppercase tracking-wide fw-bold mb-3">
                            <i class="bi bi-key me-2 text-neon-cyan"></i>{{ __('ui.change_password') }}
                        </h2>

                        @if (session('status') === 'password-updated')
                            <div class="alert alert-success bg-success bg-opacity-10 border-success text-success fs-8 py-2" role="status">
                                <i class="bi bi-check-circle-fill me-1"></i> {{ __('ui.password_updated') }}
                            </div>
                        @endif

                        <form action="{{ route('user-password.update') }}" method="POST" novalidate>
                            @csrf
                            @method('PUT')

                            @foreach ([
                                'current_password' => __('ui.current_password'),
                                'password' => __('ui.new_password'),
                                'password_confirmation' => __('ui.confirm_password'),
                            ] as $field => $label)
                                <div class="mb-3">
                                    <label for="pw_{{ $field }}" class="form-label text-muted-custom fs-8 text-uppercase tracking-wide fw-semibold">{{ $label }}</label>
                                    <div class="input-group">
                                        <input type="password" name="{{ $field }}" id="pw_{{ $field }}" required
                                            autocomplete="{{ $field === 'current_password' ? 'current-password' : 'new-password' }}"
                                            class="form-control bg-dark border-secondary text-white @error($field, 'updatePassword') is-invalid @enderror">
                                        <button type="button" class="btn btn-outline-secondary border-secondary"
                                            data-toggle-password="pw_{{ $field }}" aria-label="{{ __('ui.show_password') }}" aria-pressed="false">
                                            <i class="bi bi-eye-slash"></i>
                                        </button>
                                        @error($field, 'updatePassword')
                                            <div class="invalid-feedback font-monospace fs-8">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            @endforeach

                            <button type="submit" class="btn btn-cyber-gradient w-100 text-uppercase tracking-wider fs-7 fw-bold">
                                <i class="bi bi-shield-lock me-2"></i>{{ __('ui.update_password') }}
                            </button>
                        </form>
                    </div>
                </div>

                {{-- Dati account e riepilogo annunci --}}
                <div class="col-12 col-lg-8">
                    <div class="p-4 rounded-3 border border-secondary-subtle bg-navbar-card shadow-lg mb-4">
                        <h2 class="h6 text-white text-uppercase tracking-wide fw-bold mb-3">
                            <i class="bi bi-person-vcard me-2 text-neon-cyan"></i>{{ __('ui.account_data') }}
                        </h2>
                        <dl class="row mb-0 fs-7">
                            <dt class="col-sm-4 text-muted-custom fw-normal">{{ __('ui.username') }}</dt>
                            <dd class="col-sm-8 text-white">
                                <div class="d-flex align-items-center justify-content-between gap-2">
                                    <span class="text-break">{{ $user->name }}</span>
                                    <button type="button" class="btn btn-outline-neon-cyan btn-sm py-0 px-2 flex-shrink-0"
                                        data-bs-toggle="collapse" data-bs-target="#editNameForm"
                                        aria-expanded="{{ $errors->updateProfileInformation->any() ? 'true' : 'false' }}" aria-controls="editNameForm">
                                        <i class="bi bi-pencil me-1"></i>{{ __('ui.edit') }}
                                    </button>
                                </div>

                                @if (session('status') === 'profile-information-updated')
                                    <div class="text-success fs-8 mt-2" role="status">
                                        <i class="bi bi-check-circle-fill me-1"></i>{{ __('ui.name_updated') }}
                                    </div>
                                @endif

                                {{-- Azione Fortify già attiva: PUT /user/profile-information --}}
                                <form action="{{ route('user-profile-information.update') }}" method="POST" novalidate
                                    id="editNameForm" class="collapse mt-3 {{ $errors->updateProfileInformation->any() ? 'show' : '' }}">
                                    @csrf
                                    @method('PUT')
                                    <label for="profile_name" class="visually-hidden">{{ __('ui.username') }}</label>
                                    <div class="input-group input-group-sm">
                                        <input type="text" name="name" id="profile_name" required minlength="2" maxlength="255"
                                            value="{{ old('name', $user->name) }}" autocomplete="name"
                                            class="form-control bg-dark border-secondary text-white @error('name', 'updateProfileInformation') is-invalid @enderror">
                                        <button type="submit" class="btn btn-cyber-gradient fw-bold">
                                            <i class="bi bi-check-lg me-1"></i>{{ __('ui.save_changes') }}
                                        </button>
                                        @error('name', 'updateProfileInformation')
                                            <div class="invalid-feedback font-monospace fs-8">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </form>
                            </dd>

                            <dt class="col-sm-4 text-muted-custom fw-normal">{{ __('ui.email_address') }}</dt>
                            <dd class="col-sm-8 text-white text-break">{{ $user->email }}</dd>

                            <dt class="col-sm-4 text-muted-custom fw-normal">{{ __('ui.member_since') }}</dt>
                            <dd class="col-sm-8 text-white">{{ $user->created_at?->translatedFormat('j F Y') }}</dd>

                            <dt class="col-sm-4 text-muted-custom fw-normal">{{ __('ui.role') }}</dt>
                            <dd class="col-sm-8 text-white mb-0">{{ $user->is_revisor ? __('ui.role_revisor') : __('ui.role_user') }}</dd>
                        </dl>
                    </div>

                    <div class="row g-3 mb-4 text-center">
                        @foreach ([
                            ['key' => 'published', 'icon' => 'bi-check-circle', 'color' => 'text-success'],
                            ['key' => 'pending', 'icon' => 'bi-hourglass-split', 'color' => 'text-warning'],
                            ['key' => 'rejected', 'icon' => 'bi-x-octagon', 'color' => 'text-danger'],
                        ] as $stat)
                            <div class="col-4">
                                <div class="p-3 rounded-3 border border-secondary-subtle bg-navbar-card h-100">
                                    <i class="bi {{ $stat['icon'] }} {{ $stat['color'] }} fs-4"></i>
                                    <div class="fs-3 fw-bold text-white font-monospace">{{ $counts[$stat['key']] }}</div>
                                    <div class="text-muted-custom fs-8 text-uppercase tracking-wide">{{ __('ui.status_' . $stat['key']) }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="p-4 rounded-3 border border-secondary-subtle bg-navbar-card shadow-lg">
                        <div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
                            <h2 class="h6 text-white text-uppercase tracking-wide fw-bold mb-0">
                                <i class="bi bi-collection me-2 text-neon-cyan"></i>{{ __('ui.my_ads') }}
                            </h2>
                            <a href="{{ route('articles.create') }}" class="btn btn-outline-neon-cyan btn-sm text-uppercase fs-8 fw-bold">
                                <i class="bi bi-plus-circle me-1"></i>{{ __('ui.new_ad') }}
                            </a>
                        </div>

                        @forelse ($articles as $article)
                            <a href="{{ route('articles.show', $article) }}"
                                class="d-flex align-items-center gap-3 p-2 rounded text-decoration-none profile-article-row {{ $loop->last ? '' : 'border-bottom border-secondary-subtle' }}">
                                <img src="{{ $article->images->first()?->thumbnailUrl() ?? asset('media/placeholder.svg') }}"
                                    alt="" width="56" height="56" loading="lazy" class="rounded object-fit-cover flex-shrink-0">
                                <div class="flex-grow-1 min-w-0">
                                    <div class="text-white fw-semibold text-truncate">{{ $article->title }}</div>
                                    <div class="text-muted-custom fs-8">
                                        {{ $article->category?->translated_name ?? __('ui.no_category') }} ·
                                        {{ number_format($article->price, 2, ',', '.') }} € ·
                                        {{ $article->created_at->format('d/m/Y') }}
                                    </div>
                                </div>
                                @if ($article->isSold())
                                    <span class="badge bg-secondary">{{ __('ui.sold') }}</span>
                                @elseif ($article->isReserved())
                                    <span class="badge bg-warning text-dark">{{ __('ui.reserved') }}</span>
                                @elseif ($article->is_accepted === true)
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success">{{ __('ui.status_published') }}</span>
                                @elseif ($article->is_accepted === false)
                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger">{{ __('ui.status_rejected') }}</span>
                                @else
                                    <span class="badge bg-warning bg-opacity-10 text-warning border border-warning">{{ __('ui.status_pending') }}</span>
                                @endif
                            </a>
                        @empty
                            <p class="text-muted-custom fs-7 mb-0">{{ __('ui.no_ads_yet') }}</p>
                        @endforelse
                    </div>

                    {{-- GDPR: copia dei propri dati e cancellazione dell'account --}}
                    <div class="p-4 rounded-3 border border-secondary-subtle bg-navbar-card shadow-lg mt-4">
                        <h2 class="h6 text-white text-uppercase tracking-wide fw-bold mb-3">
                            <i class="bi bi-shield-lock me-2 text-neon-cyan"></i>{{ __('ui.your_data_title') }}
                        </h2>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="{{ route('profile.export') }}" class="btn btn-outline-neon-cyan btn-sm">
                                <i class="bi bi-download me-1"></i>{{ __('ui.account_export') }}
                            </a>
                            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="collapse" data-bs-target="#deleteAccountForm"
                                aria-expanded="{{ $errors->deleteAccount->any() ? 'true' : 'false' }}" aria-controls="deleteAccountForm">
                                <i class="bi bi-trash me-1"></i>{{ __('ui.account_delete') }}
                            </button>
                        </div>

                        <form action="{{ route('profile.destroy') }}" method="POST" id="deleteAccountForm"
                            class="collapse mt-3 {{ $errors->deleteAccount->any() ? 'show' : '' }}">
                            @csrf
                            @method('DELETE')
                            <p class="text-muted-custom fs-7">{{ __('ui.account_delete_warning') }}</p>
                            <label for="delete_password" class="form-label text-white fs-7">{{ __('ui.current_password') }}</label>
                            <div class="input-group input-group-sm">
                                <input type="password" name="password" id="delete_password" required autocomplete="current-password"
                                    class="form-control bg-dark border-secondary text-white @error('password', 'deleteAccount') is-invalid @enderror">
                                <button type="submit" class="btn btn-danger fw-bold">{{ __('ui.account_delete_button') }}</button>
                                @error('password', 'deleteAccount')
                                    <div class="invalid-feedback font-monospace fs-8">{{ $message }}</div>
                                @enderror
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>

</x-layout>
