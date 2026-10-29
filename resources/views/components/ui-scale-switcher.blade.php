{{-- "My preferences": the signed-in user's own interface size, four steps. PATCH /profile/ui-scale
     sends only the size; the server updates the authenticated user's own row and no other, so
     there is nothing here an admin or a cashier could point at somebody else.
     $tone: 'light' (white menu, profile page) | 'dark' (the teal sidebar). Hidden in a demo build, which stays at 100%. --}}
@props(['tone' => 'light'])

@unless (\App\Support\Demo::enabled() || ! auth()->check())
    @php
        $current = ui_scale();
        $on = $tone === 'dark' ? 'bg-white text-brand-700' : 'bg-brand-600 text-white';
        $off = $tone === 'dark' ? 'bg-brand-600 text-brand-100 hover:bg-brand-500' : 'bg-gray-100 text-gray-700 hover:bg-gray-200';
    @endphp
    <form method="POST" action="{{ route('profile.ui-scale') }}" {{ $attributes }}>
        @csrf
        @method('PATCH')
        <div role="group" aria-label="{{ __('profile.ui_scale') }}" class="inline-flex overflow-hidden rounded border {{ $tone === 'dark' ? 'border-brand-500' : 'border-gray-300' }}" dir="ltr">
            @foreach (\App\Models\User::UI_SCALES as $scale)
                <button type="submit" name="ui_scale" value="{{ $scale }}" aria-pressed="{{ $current === $scale ? 'true' : 'false' }}"
                        class="px-2 py-1 text-xs font-medium {{ $current === $scale ? $on : $off }}">{{ $scale }}%</button>
            @endforeach
        </div>
    </form>
@endunless
