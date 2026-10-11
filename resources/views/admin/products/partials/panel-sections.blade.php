{{--
    Product form → "Client panel": one switch per server panel section the chosen module declares,
    posted as panel_sections[key] (a hidden 0 comes first, so an untick is saved). The card shows only
    for modules with panel sections, and only the chosen module's switches are posted (x-if on the
    form's "module").

    ProductPanelSectionsComposer gives panelSectionModules: module slug => list of {key, label, icon,
    help, default, enabled}. Listing them never calls a server.
--}}
@php
    $panelSectionModules = (array) ($panelSectionModules ?? []);
    $chosenModule = (string) ($selectedModule ?? (isset($product) ? $product->server_module : '') ?? '');
@endphp
@if ($panelSectionModules !== [])
    <div x-show="@js(array_keys($panelSectionModules)).includes(module || '')" @unless (array_key_exists($chosenModule, $panelSectionModules)) style="display: none" @endunless>
        <section class="card" id="client-panel" style="display:grid;gap:1rem">
            <div class="card-header" style="margin:0"><h2>{{ __('Client panel') }}</h2></div>
            <p class="muted" style="margin:0">{{ __('Choose what clients can do in their server panel. Features you turn off in the control panel itself stay hidden too.') }}</p>
            @foreach ($panelSectionModules as $slug => $sections)
                <template x-if="module === @js((string) $slug)">
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px 18px">
                        @foreach ($sections as $section)
                            @php $id = 'f-panel-'.$slug.'-'.$section['key']; @endphp
                            <div class="field">
                                <input type="hidden" name="panel_sections[{{ $section['key'] }}]" value="0">
                                <label class="check" for="{{ $id }}">
                                    <input id="{{ $id }}" type="checkbox" name="panel_sections[{{ $section['key'] }}]" value="1" @checked($section['enabled'])>
                                    <span>
                                        {{ $section['label'] }}
                                        @unless ($section['default'])<span class="pill" style="margin-inline-start:.3rem">{{ __('Off unless you turn it on') }}</span>@endunless
                                        @if (filled($section['help']))<br><span class="help">{{ $section['help'] }}</span>@endif
                                    </span>
                                </label>
                            </div>
                        @endforeach
                    </div>
                </template>
            @endforeach
        </section>
    </div>
@endif
