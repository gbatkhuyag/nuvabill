{{-- Test stand-in for theme::client.services.server-panel, used only while the theme has no server panel view. --}}
<section data-test="server-panel" data-state="{{ $serverPanel['state'] }}">
    @foreach ($serverPanel['power'] as $power)
        <button type="submit" formaction="{{ $power['url'] }}">{{ $power['label'] }}</button>
    @endforeach
    @foreach ($serverPanel['sections'] as $section)
        <span data-section="{{ $section['key'] }}">{{ $section['label'] }}</span>
    @endforeach
</section>
