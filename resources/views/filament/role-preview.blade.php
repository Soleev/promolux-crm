@if(request()->attributes->get('preview_applied'))
    <form method="POST" action="{{ route('crm.preview.stop') }}" class="flex min-w-0 flex-wrap items-center gap-2 text-sm">
        @csrf
        <span class="hidden max-w-56 truncate lg:block">Только просмотр · {{ \App\Models\User::ROLE_LABELS[auth()->user()->role] }}@if(session('crm_preview.employee')) · {{ auth()->user()->name }}@endif</span>
        <x-filament::button type="submit" size="sm" title="Вернуться к владельцу"><span class="hidden sm:inline">Вернуться к владельцу</span><span class="sm:hidden">Выйти</span></x-filament::button>
    </form>
@elseif(auth()->user()?->role === 'owner')
    <details class="relative text-sm">
        <summary class="cursor-pointer whitespace-nowrap" aria-label="Просмотр роли"><span class="hidden sm:inline">Просмотр роли</span><span class="sm:hidden">Роль</span></summary>
        <form method="POST" action="{{ route('crm.preview.start') }}" class="absolute end-0 z-50 mt-2 grid w-64 gap-3 rounded-xl bg-white p-4 shadow-lg dark:bg-gray-900">
            @csrf
            <label>Посмотреть CRM как
                <select name="view_as" class="mt-1 w-full rounded-lg border p-2 dark:bg-gray-800" required>
                    <optgroup label="Роль без назначенных записей">
                        @foreach(\App\Models\User::ROLE_LABELS as $role=>$label)
                            @if($role !== 'owner')<option value="{{ $role }}">{{ $label }}</option>@endif
                        @endforeach
                    </optgroup>
                    <optgroup label="С данными сотрудника">
                        @foreach(\App\Models\User::where('active',true)->where('role','!=','owner')->orderBy('name')->get(['id','name','role']) as $employee)
                            <option value="user:{{ $employee->id }}">{{ $employee->name }} · {{ \App\Models\User::ROLE_LABELS[$employee->role] }}</option>
                        @endforeach
                    </optgroup>
                </select>
            </label>
            <x-filament::button type="submit" size="sm">Открыть для просмотра</x-filament::button>
        </form>
    </details>
@endif
