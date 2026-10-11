@php
    $editing = $option->exists;
    $base = $currencies[0];
    $unit = $kind->unit;
    $isMb = $unit === 'MB';
    $ladder = $kind->ladder;
    $isSwitch = $ladder === null && $kind->maxExtra === 1;
    $kindLabel = \App\Billing\ResourceKinds::isBuiltIn($kind->key) ? \App\Billing\ResourceKinds::label($kind->key) : $kind->label;
    $reposted = old('_token') !== null;

    // RAM is stored in MB; whole gigabytes are shown in GB.
    $inGb = fn (?int $units): bool => $isMb && $units !== null && $units > 0 && $units % 1024 === 0;
    $stepUnit = $isMb ? (string) old('step_unit', $inGb($option->step_size) ? 'GB' : 'MB') : $unit;
    $stepValue = (string) old('step_size', $inGb($option->step_size) ? intdiv((int) $option->step_size, 1024) : $option->step_size);
    $includedUnit = $isMb ? (string) old('included_unit', $inGb($option->included) ? 'GB' : 'MB') : $unit;
    $includedValue = (string) old('included', $inGb($option->included) ? intdiv((int) $option->included, 1024) : $option->included);

    $form = [
        'label' => (string) old('label', $option->label),
        'description' => (string) old('description', $option->description),
        'step_size' => $stepValue,
        'step_unit' => $stepUnit,
        'included' => $includedValue,
        'included_unit' => $includedUnit,
        'min_steps' => (string) old('min_steps', $option->min_steps),
        'max_steps' => (string) old('max_steps', $option->max_steps),
        'default_steps' => (string) old('default_steps', $option->default_steps),
        'stock' => (string) old('stock', $option->stock),
    ];

    $factor = $isMb ? ['MB' => 1, 'GB' => 1024] : [];
    $stepUnits = $ladder !== null ? 1 : (int) round((float) $form['step_size'] * ($factor[$stepUnit] ?? 1));
    $includedUnits = $form['included'] === '' ? null : (int) round((float) $form['included'] * ($factor[$includedUnit] ?? 1));
    $maxSteps = max(1, min(1000, (int) $form['max_steps']));
    $minSteps = max(0, min($maxSteps, (int) $form['min_steps']));
    $stockLeft = $form['stock'] === '' ? null : max(0, (int) $form['stock'] - $usedSteps);
    $previewMax = $stockLeft === null ? $maxSteps : max($minSteps, min($maxSteps, $stockLeft));
    $basePrices = collect($cycles)->filter(fn ($cycle) => isset($priceValues[$base][$cycle->value]))
        ->mapWithKeys(fn ($cycle) => [$cycle->value => ['step' => $priceValues[$base][$cycle->value]['price'], 'setup' => $priceValues[$base][$cycle->value]['setup_fee']]])->all();

    $previewOption = [
        'kind' => $kind->key,
        'label' => $form['label'] !== '' ? $form['label'] : $kind->label,
        'description' => $form['description'] !== '' ? $form['description'] : null,
        'unit' => $unit,
        'step' => max(1, $stepUnits),
        'included' => $includedUnits,
        'min' => $minSteps,
        'max' => $previewMax,
        'value' => min(max((int) $form['default_steps'], $minSteps), $previewMax),
        'toggle' => $ladder === null && $maxSteps === 1,
        'ladder' => $ladder,
        'restarts' => $kind->restarts,
        'note' => $kind->note ?? ($kind->restarts ? __('Takes effect after a restart.') : null),
        'stock_left' => $stockLeft,
        'currency' => $base,
        'prices' => $basePrices,
    ];

    $editorConfig = [
        'kind' => [
            'key' => $kind->key,
            'label' => $kind->label,
            'unit' => $unit,
            'minStep' => $kind->minStep,
            'stepMultiple' => $kind->stepMultiple,
            'maxExtra' => $kind->maxExtra,
            'ladder' => $ladder,
            'restarts' => $kind->restarts,
            'note' => $kind->note,
        ],
        'form' => $form,
        'cycles' => collect($cycles)->map(fn ($cycle) => ['value' => $cycle->value, 'label' => $cycle->label()])->values()->all(),
        'base' => $base,
        'usedSteps' => $usedSteps,
        'baseUrl' => $canReadBase ? route('admin.products.resources.base', $product) : null,
        'csrf' => csrf_token(),
        'locale' => $locale,
        'unlimitedZero' => $product->server_module === 'virtualizor',
        'text' => [
            'restarts' => __('Takes effect after a restart.'),
            'noPrice' => __('No price for :cycle, so clients who pay :cycle do not see this option.'),
            'minStep' => __('Each step must add at least :amount.'),
            'multiple' => __('Each step must be a multiple of :amount.'),
            'ladder' => __('This kind has :count sizes above the plan, so choose at most :count.'),
            'maxExtra' => __('Most extra steps × step size is more than this server type allows (:max).'),
            'minMax' => __('The fewest steps cannot be more than the most steps.'),
            'defaultRange' => __('Steps chosen at first must be between the fewest and the most.'),
            'unlimited' => __('The plan has unlimited traffic, so extra traffic does nothing.'),
            'baseFailed' => __('The server did not answer. Check the server and try again.'),
            'baseUnknown' => __('The server does not say how much the plan includes.'),
            'baseDone' => __('The plan includes :amount.'),
        ],
    ];

    $quoteText = [
        'extras' => __('Extras: :amount'),
        'noExtras' => __('No extras chosen.'),
        'setup' => str_replace(':fee', ':amount', __('+ :fee setup')),
        'suffixes' => collect(\App\Enums\BillingCycle::cases())->mapWithKeys(fn ($case) => [$case->value => $case->suffix()])->all(),
    ];
    $fieldError = fn (string $key): ?string => $errors->first($key) ?: null;
@endphp
<x-layouts.admin :title="($editing ? $option->displayLabel() : __('New option')).' · '.$product->name">
    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.products.index') }}">{{ __('Products') }}</a> · <a href="{{ route('admin.products.resources.index', $product) }}">{{ $product->name }}</a></p>
            <h1>{{ $editing ? $option->displayLabel() : __('New option: :kind', ['kind' => $kind->label]) }}</h1>
            <p>{{ __('Choose the size of each step, how many steps clients can add, and the price of each step.') }}</p>
        </div>
    </div>

    @include('admin.products.partials.resource-tabs')

    @if ($editing && ! $supported)
        <div class="flash" data-tone="warn" role="alert"><x-icon name="alert" style="width:18px;height:18px;flex:none;color:var(--nb-warn)" /><span>{{ __('The server module does not sell this kind now, so these extras are not set on the server. Clients keep what they have. You can still change the prices or hide the option.') }}</span></div>
    @endif
    @if ($used)
        <div class="flash" data-tone="info"><x-icon name="lock" style="width:18px;height:18px;flex:none;color:var(--nb-info)" /><span>{{ __('Services use this option, so its kind and step size are locked. Make a new option to sell other steps.') }}</span></div>
    @endif

    <form method="POST" action="{{ $editing ? route('admin.products.resources.update', [$product, $option]) : route('admin.products.resources.store', $product) }}"
          class="resource-layout" x-data="resourceOptionEditor(@js($editorConfig))" x-effect="emitPreview()" x-on:price-change="onPrices($event.detail)">
        @csrf
        @if ($editing) @method('PUT') @else <input type="hidden" name="kind" value="{{ $kind->key }}"> @endif

        <div class="resource-main">
            <section class="card" style="display:grid;gap:1rem" aria-labelledby="sec-option">
                <div class="card-header" style="margin:0"><h2 id="sec-option">{{ __('Option') }}</h2></div>
                <div class="form-grid">
                    <div class="field">
                        <span class="label">{{ __('Kind') }}</span>
                        <p style="margin:0;display:flex;align-items:center;gap:8px">
                            <span class="resource-icon" aria-hidden="true" style="width:30px;height:30px"><x-icon :name="\App\Billing\ResourceKinds::icon($kind->key)" /></span>
                            <span><b><bdi>{{ $kindLabel }}</bdi></b> <span class="faint mono">{{ $kind->key }}</span></span>
                        </p>
                        <p class="help">{{ match (true) {
                            $ladder !== null => __('Each step is one size up. Step 0 is the plan itself.'),
                            $isSwitch => __('This option is on or off.'),
                            $unit === 'MB' => __('Counted in MB. You can type GB.'),
                            $unit !== '' => __('Counted in :unit.', ['unit' => $unit]),
                            default => __('Counted in steps.'),
                        } }}</p>
                    </div>
                    <x-input name="label" :label="__('Name shown to clients (optional)')" :value="$form['label']" maxlength="60" :placeholder="$kind->label" x-model="form.label" />
                    <x-input name="description" :label="__('Short description (optional)')" :value="$form['description']" maxlength="200" class="span-2" :placeholder="__('For example “For busy websites”.')" x-model="form.description" />
                </div>
            </section>

            <section class="card" style="display:grid;gap:1rem" aria-labelledby="sec-steps">
                <div class="card-header" style="margin:0"><h2 id="sec-steps">{{ __('Steps') }}</h2></div>

                @if ($ladder !== null)
                    <input type="hidden" name="step_size" value="1">
                    <div class="field">
                        <span class="label">{{ __('Sizes clients can choose') }}</span>
                        <ol class="resource-ladder">
                            @foreach ($ladder as $index => $size)
                                <li><x-pill :tone="$index === 0 ? null : 'accent'">{{ $index === 0 ? __(':size (the plan)', ['size' => $size]) : __('Step :step: :size', ['step' => $index, 'size' => $size]) }}</x-pill></li>
                            @endforeach
                        </ol>
                    </div>
                @endif

                <div class="form-grid">
                    @if ($ladder === null && ! $isSwitch)
                        <div class="field">
                            <label for="f-step_size">{{ __('Each step adds') }} <span aria-hidden="true">*</span></label>
                            <div class="input-unit">
                                <input id="f-step_size" name="step_size" type="number" class="input num" min="1" required inputmode="decimal"
                                       value="{{ $form['step_size'] }}" step="{{ $stepUnit === 'GB' ? 'any' : '1' }}" x-bind:step="form.step_unit === 'GB' ? 'any' : '1'"
                                       x-model="form.step_size" @readonly($used) aria-describedby="f-step_size-help"
                                       @if ($fieldError('step_size')) aria-invalid="true" @endif>
                                @if ($isMb && ! $used)
                                    <label class="sr-only" for="f-step_unit">{{ __('Unit') }}</label>
                                    <select id="f-step_unit" name="step_unit" class="select" x-bind:value="form.step_unit" x-on:change="switchUnit('step_size', 'step_unit', $event.target.value)">
                                        <option value="MB" @selected($stepUnit === 'MB')>MB</option>
                                        <option value="GB" @selected($stepUnit === 'GB')>GB</option>
                                    </select>
                                @else
                                    @if ($isMb)<input type="hidden" name="step_unit" value="{{ $stepUnit }}">@endif
                                    <span class="input-unit-label">{{ $isMb ? $stepUnit : ($unit !== '' ? $unit : __('steps')) }}</span>
                                @endif
                            </div>
                            @if ($fieldError('step_size'))
                                <p class="error" id="f-step_size-help">{{ $fieldError('step_size') }}</p>
                            @else
                                <p class="help" id="f-step_size-help">{{ $used ? __('Locked: services use this option.') : __('The smallest step is :amount.', ['amount' => \App\Billing\ResourceKinds::format($unit, $kind->minStep)]) }}</p>
                            @endif
                        </div>
                    @else
                        @unless ($ladder !== null)
                            <input type="hidden" name="step_size" value="1">
                        @endunless
                    @endif

                    @if ($ladder === null && ! ($isSwitch && $unit === ''))
                        <div class="field">
                            <label for="f-included">{{ __('Included in the plan (optional)') }}</label>
                            <div class="input-unit">
                                <input id="f-included" name="included" type="number" class="input num" min="0" inputmode="decimal"
                                       value="{{ $form['included'] }}" step="{{ $includedUnit === 'GB' ? 'any' : '1' }}" x-bind:step="form.included_unit === 'GB' ? 'any' : '1'"
                                       x-model="form.included" aria-describedby="f-included-help" @if ($fieldError('included')) aria-invalid="true" @endif>
                                @if ($isMb)
                                    <label class="sr-only" for="f-included_unit">{{ __('Unit') }}</label>
                                    <select id="f-included_unit" name="included_unit" class="select" x-bind:value="form.included_unit" x-on:change="switchUnit('included', 'included_unit', $event.target.value)">
                                        <option value="MB" @selected($includedUnit === 'MB')>MB</option>
                                        <option value="GB" @selected($includedUnit === 'GB')>GB</option>
                                    </select>
                                @elseif ($unit !== '')
                                    <span class="input-unit-label">{{ $unit }}</span>
                                @endif
                                @if ($canReadBase)
                                    <button type="button" class="btn btn-sm" x-cloak x-on:click="readBase()" x-bind:disabled="baseLoading" x-bind:aria-busy="baseLoading ? 'true' : 'false'">
                                        <x-icon name="download" /><span x-text="baseLoading ? @js(__('Reading…')) : @js(__('Read from server'))">{{ __('Read from server') }}</span>
                                    </button>
                                @endif
                            </div>
                            @if ($fieldError('included'))
                                <p class="error" id="f-included-help">{{ $fieldError('included') }}</p>
                            @else
                                <p class="help" id="f-included-help">{{ __('Only shown to clients, next to the slider. Leave it empty when you do not know.') }}</p>
                            @endif
                            <p class="help" role="status" aria-live="polite" x-show="baseMessage !== ''" x-text="baseMessage" x-cloak></p>
                        </div>
                    @endif
                </div>

                <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">
                    <x-input name="min_steps" type="number" min="0" max="1000" :label="__('Fewest extra steps')" :value="$form['min_steps']" required x-model="form.min_steps" :help="__('Clients must take at least this many when they order.')" />
                    @if ($isSwitch)
                        <input type="hidden" name="max_steps" value="1">
                        <div class="field"><span class="label">{{ __('Most extra steps') }}</span><p style="margin:.45rem 0 0">1</p></div>
                    @else
                        <x-input name="max_steps" type="number" min="1" :max="$ladder !== null ? count($ladder) - 1 : 1000" :label="__('Most extra steps')" :value="$form['max_steps']" required x-model="form.max_steps" :help="__('A step count of 1 shows as an on/off switch.')" />
                    @endif
                    <x-input name="default_steps" type="number" min="0" max="1000" :label="__('Steps chosen at first')" :value="$form['default_steps']" required x-model="form.default_steps" :help="__('What the order form starts with.')" />
                </div>

                <x-input name="stock" type="number" min="0" max="1000000" :label="__('Stock (optional)')" :value="$form['stock']" x-model="form.stock"
                         :help="$usedSteps > 0 ? __('Extra steps for all clients together. Leave empty for no limit. Clients have :count steps now.', ['count' => $usedSteps]) : __('Extra steps for all clients together. Leave empty for no limit.')" />
            </section>

            <section class="card" style="display:grid;gap:.75rem" aria-labelledby="sec-clients">
                <div class="card-header" style="margin:0"><h2 id="sec-clients">{{ __('Clients') }}</h2></div>
                <x-checkbox name="client_can_add" :label="__('Clients can add more later')" :help="__('On their service page, after the order. They pay for the days left.')" :checked="(bool) $option->client_can_add" />
                @if ($kind->canShrink)
                    <x-checkbox name="client_can_remove" :label="__('Clients can remove extras')" :help="__('What they paid for the days left goes back as credit, as your downgrade setting says.')" :checked="(bool) $option->client_can_remove" />
                @else
                    <input type="hidden" name="client_can_remove" value="0">
                    <div class="field">
                        <label class="check" for="f-client_can_remove"><input id="f-client_can_remove" type="checkbox" disabled><span>{{ __('Clients can remove extras') }}<br><span class="help">{{ __(':kind can only grow, so clients cannot remove it.', ['kind' => $kind->label]) }}</span></span></label>
                    </div>
                @endif
                <x-checkbox name="is_active" :label="__('Show in the store and client area')" :help="__('A hidden option stays on the services that have it, and they keep paying for it.')" :checked="(bool) $option->is_active" />
            </section>

            <section class="card" style="display:grid;gap:1rem" aria-labelledby="sec-prices">
                <div class="card-header" style="margin:0"><h2 id="sec-prices">{{ __('Price for each step') }}</h2></div>
                @if ($cycles === [])
                    <div class="flash" data-tone="warn" role="alert"><span>{{ __('Give the product a price first. Options are priced for the billing cycles of the product.') }} <a href="{{ route('admin.products.edit', $product) }}">{{ __('Edit product') }}</a></span></div>
                @else
                    <x-price-grid name="prices" :currencies="$currencies" :cycles="$cycles" :values="$priceValues" :base="$base" :rates="$rates" per-step id="option-prices" />
                @endif
            </section>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">{{ $editing ? __('Save option') : __('Add option') }}</button>
                <a class="btn" href="{{ route('admin.products.resources.index', $product) }}">{{ __('Cancel') }}</a>
            </div>
        </div>

        <aside class="card resource-preview" aria-labelledby="preview-title">
            <div class="card-header">
                <h2 id="preview-title">{{ __('What clients see') }}</h2>
                <x-pill tone="accent">{{ __('Preview') }}</x-pill>
            </div>
            @if ($cycles !== [])
                <div class="choice-chips" role="group" aria-label="{{ __('Billing cycle') }}">
                    @foreach ($cycles as $cycle)
                        <button type="button" x-on:click="cycle = @js($cycle->value)" x-bind:aria-pressed="cycle === @js($cycle->value) ? 'true' : 'false'" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">{{ $cycle->label() }}</button>
                    @endforeach
                </div>
            @endif
            <p class="faint" style="margin:0;font-size:.8rem" x-cloak x-show="priceTab !== @js($base)" x-text="@js(__('Prices in :currency. Pick another currency in the price grid.')).replace(':currency', priceTab)"></p>
            <div class="resource-sliders" x-data="resourceQuote(@js(['currency' => $base, 'locale' => $locale, 'text' => $quoteText]))" x-on:resource-change="track($event.detail)">
                <x-resource-slider :option="$previewOption" :currency="$base" :name="false" id="pv-option" live />
                <p class="resource-extras" x-text="totalText()" aria-live="polite"></p>
            </div>
            <ul class="resource-warnings" aria-live="polite">
                <template x-for="warning in warnings()" :key="warning">
                    <li class="flash" data-tone="warn" x-text="warning"></li>
                </template>
            </ul>
            <p class="help" style="margin:0;font-size:.8rem;color:var(--nb-faint)">{{ __('The preview follows the form as you type. Nothing is saved until you press Save.') }}</p>
        </aside>
    </form>
</x-layouts.admin>
