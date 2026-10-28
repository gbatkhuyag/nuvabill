@props([
    'name',
    'label',
    'checked' => false,
    'help' => null,
])
@php
    $id = $attributes->get('id', 'f-'.str_replace(['.', '[', ']'], '-', $name));
    $errorKey = str_replace(['[', ']'], ['.', ''], $name);
    // The hidden 0 below means a submitted box always has old input; none means another form on
    // the page was sent, so the box keeps its default (a box ticked for safety stays ticked).
    $isChecked = old('_token') !== null && old($errorKey) !== null ? (bool) old($errorKey) : (bool) $checked;
@endphp
<div class="field {{ $attributes->get('class') }}">
    <input type="hidden" name="{{ $name }}" value="0">
    <label class="check" for="{{ $id }}">
        <input id="{{ $id }}" type="checkbox" name="{{ $name }}" value="1" @checked($isChecked) {{ $attributes->except(['class', 'id']) }}>
        <span>{{ $label }}@if ($help)<br><span class="help">{{ $help }}</span>@endif</span>
    </label>
    @error($errorKey)
        <p class="error">{{ $message }}</p>
    @enderror
</div>
