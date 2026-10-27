{{--
    The note staff see while no power driver is installed: automatic power control is the paid
    Dedicated Server Power add-on. Values: $mayMarketplace (whether to link to the marketplace).
--}}
<p class="muted" style="margin:0;font-size:.9rem">
    {{ \App\Provisioning\Hardware\PowerControl::needsAddonMessage() }}
    @if ($mayMarketplace ?? false)
        <a href="{{ route('admin.marketplace.show', 'dedicated-server-power') }}">{{ __('Get Dedicated Server Power') }}</a>
    @endif
</p>
