@extends('layouts.admin', [
    'heading' => 'Доступы пользователей',
    'subheading' => 'Текущие роли, статус и персональные доступы к разделам админки.',
])

@section('content')
    @php
        $permissionsByGroup = collect($permissions)->groupBy('group');
    @endphp

    <div class="grid">
        @foreach ($users as $user)
            @php
                $rolePermissions = $user->rolePermissions();
                $extraPermissions = $user->extra_permissions ?? [];
                $hasWildcard = in_array('*', $rolePermissions, true);
            @endphp

            <form method="POST" action="{{ route('admin.users.update', $user) }}" class="panel">
                @csrf
                @method('PATCH')

                <div class="topbar" style="margin-bottom:18px;">
                    <div>
                        <h2 style="margin:0;">{{ $user->name }}</h2>
                        <div class="help" style="margin-top:6px;">{{ $user->email }}</div>
                    </div>
                    <div class="actions">
                        <span class="tag {{ $user->is_active ? '' : 'tag-danger' }}">{{ $user->is_active ? 'Активен' : 'Отключен' }}</span>
                        <button type="submit" class="btn btn-small">Сохранить</button>
                    </div>
                </div>

                <div class="form-grid">
                    <div>
                        <label for="role-{{ $user->id }}"></label>
                        <select id="role-{{ $user->id }}" name="role">
                            @foreach ($roles as $role => $settings)
                                <option value="{{ $role }}" @selected($user->role === $role)>{{ $settings['label'] ?? $role }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label>&nbsp;</label>
                        <label style="display:flex;align-items:center;gap:8px;font-weight:600;">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" style="width:auto;" @checked($user->is_active)>
                            Пользователь активен
                        </label>
                    </div>
                </div>

                @if ($hasWildcard)
                    <div class="flash" style="margin:18px 0 0;">
                        У роли есть полный доступ ко всем разделам. Персональные галочки ниже можно оставить для будущей смены роли.
                    </div>
                @endif

                <div class="grid grid-2" style="margin-top:18px;">
                    @foreach ($permissionsByGroup as $group => $groupPermissions)
                        <div style="border:1px solid var(--line);border-radius:14px;padding:14px;">
                            <h3 style="margin:0 0 10px;">{{ $group }}</h3>
                            <div class="grid" style="gap:10px;">
                                @foreach ($groupPermissions as $permission => $settings)
                                    @php
                                        $fromRole = $hasWildcard || in_array($permission, $rolePermissions, true);
                                        $checked = $fromRole || in_array($permission, $extraPermissions, true);
                                    @endphp

                                    <label style="display:flex;align-items:flex-start;gap:8px;margin:0;font-weight:600;">
                                        <input
                                            type="checkbox"
                                            name="extra_permissions[]"
                                            value="{{ $permission }}"
                                            style="width:auto;margin-top:3px;"
                                            @checked($checked)
                                        >
                                        <span>
                                            {{ $settings['label'] ?? $permission }}
                                            @if ($fromRole)
                                                <span class="help">из роли</span>
                                            @endif
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </form>
        @endforeach
    </div>
@endsection
