{{--
    Fields the product's server module asks for when ordering (an SSH key, a hostname), from
    OrderFields::forStore(): label, type (text, textarea, select or ssh-key), help, required, max,
    options, secret, name (the form name) and error (the key of its error). Each posts with the
    form. A secret field is posted as secret_fields[...] and is never filled in again after an error.
    Needs $orderFields (may be missing in older controllers).
--}}
@php $orderFields = $orderFields ?? []; @endphp
@if ($orderFields !== [])
    <fieldset style="border:0;padding:0;margin:0;display:grid;gap:.9rem">
        <legend class="label" style="margin-bottom:.5rem">{{ __('Server details') }}</legend>
        @foreach ($orderFields as $key => $field)
            @php
                $id = 'order-field-'.$key;
                $value = $field['secret'] ? null : old($field['error']);
                $hasError = $errors->has($field['error']);
                $help = $field['type'] === 'ssh-key' && $field['help'] === null
                    ? __('Your public key, such as one that starts with ssh-ed25519. One per line.')
                    : $field['help'];
            @endphp
            <div class="field">
                <label for="{{ $id }}">{{ $field['label'] }}@if ($field['required']) <span aria-hidden="true">*</span>@endif</label>
                @if ($field['type'] === 'select')
                    <select id="{{ $id }}" name="{{ $field['name'] }}" class="select" @required($field['required']) @if ($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
                        @unless ($field['required'])<option value="">{{ __('None') }}</option>@endunless
                        @foreach ($field['options'] as $optionValue => $optionLabel)
                            <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
                        @endforeach
                    </select>
                @elseif ($field['type'] === 'text')
                    <input id="{{ $id }}" name="{{ $field['name'] }}" type="{{ $field['secret'] ? 'password' : 'text' }}" class="input" maxlength="{{ $field['max'] }}" autocomplete="off" @required($field['required']) @if ($value !== null) value="{{ $value }}" @endif @if ($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
                @else
                    <textarea id="{{ $id }}" name="{{ $field['name'] }}" class="textarea @if ($field['type'] === 'ssh-key') mono @endif" rows="{{ $field['type'] === 'ssh-key' ? 3 : 5 }}" maxlength="{{ $field['max'] }}" autocomplete="off" spellcheck="false" @if ($field['type'] === 'ssh-key') dir="ltr" @endif @required($field['required']) @if ($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>{{ $value }}</textarea>
                @endif
                @if ($hasError)
                    <p class="error" id="{{ $id }}-error">{{ $errors->first($field['error']) }}</p>
                @elseif ($help)
                    <p class="help">{{ $help }}</p>
                @endif
            </div>
        @endforeach
    </fieldset>
@endif
