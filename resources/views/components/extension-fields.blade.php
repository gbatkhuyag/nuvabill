@props(['fields', 'values' => [], 'prefix' => 'settings'])
{{--
    Renders the settings form an extension describes in settingsFields() or productFields(). A field
    marked "secret" (and a password) is never filled in: left empty, the saved value stays. On the
    product form (prefix "module_config") a textarea may be up to its "max" long (16 KB at most) and
    every other field 190 characters (see ModuleConfig).
--}}
@foreach ($fields as $key => $field)
    @php
        $name = $prefix.'['.$key.']';
        $secret = ($field['secret'] ?? false) === true;
        $value = $values[$key] ?? null;
        $saved = filled($value);
        $value = $secret ? null : $value;
        // Null leaves the attribute out.
        $limit = $prefix === 'module_config' ? \App\Extensions\Servers\ModuleConfig::maxLength($field) : null;
        $help = $secret && $saved ? __('Saved. Leave empty to keep it.') : ($field['help'] ?? null);
        $required = ($field['required'] ?? false) && ! ($secret && $saved);
    @endphp
    @switch($field['type'])
        @case('textarea')
            <x-textarea :name="$name" :label="$field['label']" :value="$value" :help="$help" :required="$required" class="span-2" :maxlength="$limit" :rows="$limit !== null && $limit > 190 ? 8 : null" :autocomplete="$secret ? 'off' : null" :refill="! $secret" />
            @break
        @case('select')
            <x-select :name="$name" :label="$field['label']" :options="$field['options'] ?? []" :value="$value" :help="$field['help'] ?? null" :required="$field['required'] ?? false" />
            @break
        @case('password')
            <x-input type="password" :name="$name" :label="$field['label']" :help="$saved ? __('Saved. Leave empty to keep it.') : ($field['help'] ?? null)" autocomplete="new-password" />
            @break
        @default
            @if ($secret)
                <x-input type="password" :name="$name" :label="$field['label']" :help="$help" :required="$required" autocomplete="new-password" :maxlength="$limit" />
            @else
                <x-input :name="$name" :label="$field['label']" :value="$value" :help="$help" :required="$required" :maxlength="$limit" />
            @endif
    @endswitch
@endforeach
