@can('impersonate', $user)
    <form method="post" action="{{ route('users.impersonate', $user) }}" @if($stopPropagation ?? false) @click.stop @endif>
        @csrf
        <button type="submit" @isset($buttonClass) class="{{ $buttonClass }}" @endisset>{{ $label ?? 'Login as user' }}</button>
    </form>
@endcan
