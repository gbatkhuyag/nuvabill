@php
    $firstCycle = $cycles[0] ?? null;
    $previewText = [
        'extras' => __('Extras: :amount'),
        'noExtras' => __('No extras chosen.'),
        'setup' => str_replace(':fee', ':amount', __('+ :fee setup')),
        'suffixes' => collect(\App\Enums\BillingCycle::cases())->mapWithKeys(fn ($case) => [$case->value => $case->suffix()])->all(),
    ];
    $hasPreview = collect($preview)->flatten(1)->isNotEmpty();
@endphp
<x-layouts.admin :title="__('Resource options').' · '.$product->name">
    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.products.index') }}">{{ __('Products') }}</a></p>
            <h1>{{ $product->name }}</h1>
            <p>{{ __('Sliders clients use to add RAM, disk, IP addresses and more to this plan. Each step has its own price.') }}</p>
        </div>
        @if ($addable !== [])
            <form method="GET" action="{{ route('admin.products.resources.create', $product) }}" class="resource-add">
                <label class="sr-only" for="add-kind">{{ __('Kind of option') }}</label>
                <select id="add-kind" name="kind" class="select">
                    @foreach ($addable as $key => $kind)
                        <option value="{{ $key }}">{{ $kind->label }}</option>
                    @endforeach
                </select>
                <button class="btn btn-primary" type="submit"><x-icon name="plus" />{{ __('Add option') }}</button>
            </form>
        @endif
    </div>

    @include('admin.products.partials.resource-tabs')

    @if ($kindsProblem)
        <div class="flash" data-tone="warn" role="alert"><x-icon name="alert" style="width:18px;height:18px;flex:none;color:var(--nb-warn)" /><span>{{ $kindsProblem }}</span></div>
    @endif
    @if ($notice)
        <div class="flash" data-tone="info"><x-icon name="server" style="width:18px;height:18px;flex:none;color:var(--nb-info)" /><span>{{ $notice }}</span></div>
    @endif
    @if ($unconverted)
        <div class="flash" data-tone="warn" role="alert"><x-icon name="alert" style="width:18px;height:18px;flex:none;color:var(--nb-warn)" /><span>{{ __('This product still has resource add-on JSON that could not be moved to sliders. Set up the options here, then clear the JSON in the product settings.') }}</span></div>
    @endif
    @if ($converted)
        <div class="flash" data-tone="info"><x-icon name="refresh" style="width:18px;height:18px;flex:none;color:var(--nb-info)" /><span>{{ __('These options were made from the resource add-ons this product had before. Check the prices and raise the most extra steps as you like.') }}</span></div>
    @endif
    @if ($cycles === [])
        <div class="flash" data-tone="warn" role="alert"><x-icon name="alert" style="width:18px;height:18px;flex:none;color:var(--nb-warn)" /><span>{{ __('Give the product a price first. Options are priced for the billing cycles of the product.') }} <a href="{{ route('admin.products.edit', $product) }}">{{ __('Edit product') }}</a></span></div>
    @endif

    <div class="resource-layout">
        <section class="resource-main" aria-labelledby="options-title">
            <h2 id="options-title" class="sr-only">{{ __('Resource options') }}</h2>

            @if ($cards === [])
                <div class="card">
                    <div class="empty">
                        <strong>{{ __('No resource options yet') }}</strong>
                        {{ __('Let clients add RAM, disk or IP addresses with a slider. You choose the size of each step, how many steps they can add and the price of each step.') }}
                    </div>
                </div>
            @else
                <ol class="resource-cards" style="list-style:none;margin:0;padding:0">
                    @foreach ($cards as $card)
                        @php $option = $card['option']; $label = $option->displayLabel(); @endphp
                        <li class="resource-card" @unless ($option->is_active) data-hidden @endunless>
                            <span class="resource-icon" aria-hidden="true"><x-icon :name="$card['icon']" /></span>
                            <div style="min-width:0">
                                <h3><a class="row-link" href="{{ route('admin.products.resources.edit', [$product, $option]) }}"><bdi>{{ $label }}</bdi></a></h3>
                                <p class="resource-card-meta">{{ $card['summary'] }} · {{ $card['price'] }}</p>
                                <div class="resource-card-pills">
                                    @if ($option->is_active)
                                        <x-pill tone="good">{{ __('Shown') }}</x-pill>
                                    @else
                                        <x-pill>{{ __('Hidden') }}</x-pill>
                                    @endif
                                    @if ($card['usedBy'] > 0)
                                        <x-pill tone="info">{{ trans_choice('Used by :count service|Used by :count services', $card['usedBy'], ['count' => $card['usedBy']]) }}</x-pill>
                                    @endif
                                    @if ($card['stockLeft'] !== null)
                                        <x-pill :tone="$card['stockLeft'] > 0 ? 'accent' : 'crit'">{{ __('Stock: :count steps left', ['count' => $card['stockLeft']]) }}</x-pill>
                                    @endif
                                    @if (! $option->client_can_add)
                                        <x-pill>{{ __('Only at order') }}</x-pill>
                                    @endif
                                    @if ($card['missing'] !== [])
                                        <x-pill tone="warn">{{ __('No price for :cycles', ['cycles' => implode(', ', $card['missing'])]) }}</x-pill>
                                    @endif
                                    @if ($card['unsupported'])
                                        <x-pill tone="crit">{{ __('Not supported by the module') }}</x-pill>
                                    @endif
                                </div>
                            </div>
                            <div class="resource-card-actions">
                                <a class="btn btn-sm" href="{{ route('admin.products.resources.edit', [$product, $option]) }}">{{ __('Edit') }}</a>
                                <form method="POST" action="{{ route('admin.products.resources.order', $product) }}">
                                    @csrf
                                    <input type="hidden" name="option" value="{{ $option->id }}">
                                    <button class="btn btn-sm btn-ghost" type="submit" name="action" value="up" @disabled($loop->first) title="{{ __('Move up') }}" aria-label="{{ __('Move :name up', ['name' => $label]) }}"><x-icon name="chevron-up" /></button>
                                    <button class="btn btn-sm btn-ghost" type="submit" name="action" value="down" @disabled($loop->last) title="{{ __('Move down') }}" aria-label="{{ __('Move :name down', ['name' => $label]) }}"><x-icon name="chevron-down" /></button>
                                    <button class="btn btn-sm btn-ghost" type="submit" name="action" value="{{ $option->is_active ? 'hide' : 'show' }}">{{ $option->is_active ? __('Hide') : __('Show') }}</button>
                                </form>
                                @if ($card['deletable'])
                                    <form method="POST" action="{{ route('admin.products.resources.destroy', [$product, $option]) }}" data-confirm="{{ __('Delete this option?') }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-ghost btn-danger" type="submit" title="{{ __('Delete') }}" aria-label="{{ __('Delete :name', ['name' => $label]) }}"><x-icon name="trash" /></button>
                                    </form>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        <aside class="card resource-preview" aria-labelledby="preview-title" x-data="{ cycle: @js($firstCycle?->value), currency: @js(array_key_first($preview)) }">
            <div class="card-header">
                <h2 id="preview-title">{{ __('What clients see') }}</h2>
                <x-pill tone="accent">{{ __('Preview') }}</x-pill>
            </div>

            @if ($hasPreview && $cycles !== [])
                <div class="choice-chips" role="group" aria-label="{{ __('Billing cycle') }}">
                    @foreach ($cycles as $cycle)
                        <button type="button" x-on:click="cycle = @js($cycle->value)" x-bind:aria-pressed="cycle === @js($cycle->value) ? 'true' : 'false'" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">{{ $cycle->label() }}</button>
                    @endforeach
                </div>
                @if (count($preview) > 1)
                    <div class="choice-chips" role="group" aria-label="{{ __('Currency') }}">
                        @foreach (array_keys($preview) as $code)
                            <button type="button" x-on:click="currency = @js($code)" x-bind:aria-pressed="currency === @js($code) ? 'true' : 'false'" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">{{ $code }}</button>
                        @endforeach
                    </div>
                @endif

                @foreach ($preview as $code => $sliders)
                    <div class="resource-sliders" @unless ($loop->first) x-cloak @endunless x-show="currency === @js($code)"
                         x-data="resourceQuote(@js(['currency' => $code, 'locale' => str_replace('_', '-', \App\Support\Locales::numberLocale()), 'text' => $previewText]))"
                         x-on:resource-change="track($event.detail)">
                        @forelse ($sliders as $slider)
                            <x-resource-slider :option="$slider" :currency="$code" :name="false" :id="'pv-'.$code.'-'.$slider['kind']" />
                        @empty
                            <p class="muted" style="margin:0">{{ __('No option has a price in :currency yet.', ['currency' => $code]) }}</p>
                        @endforelse
                        @if ($sliders !== [])
                            <p class="resource-extras" x-text="totalText()" aria-live="polite"></p>
                        @endif
                    </div>
                @endforeach
                <p class="help" style="margin:0;font-size:.8rem;color:var(--nb-faint)">{{ __('Only options that are shown and have a price appear here, as in the store. Moving the sliders saves nothing.') }}</p>
            @else
                <p class="muted" style="margin:0">{{ __('Options you add appear here as clients see them in the store.') }}</p>
            @endif
        </aside>
    </div>
</x-layouts.admin>
