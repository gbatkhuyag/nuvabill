{{--
    Service page → "Client access": "Test panel sign-in" runs the module's checks (results come back
    in the flash, one row each) and "Link an existing account" connects the service to an account
    that already exists on the server, with the fields the module asks for. A password field is
    never filled in again.

    ServiceClientAccessComposer gives clientAccess: null, or {module, check_url, checks, summary,
    link_url, link_fields}.
--}}
@if (! empty($clientAccess))
    @php
        $errors ??= new \Illuminate\Support\ViewErrorBag;
        $statusLabels = ['ok' => __('Passed'), 'warn' => __('Check'), 'fail' => __('Failed'), 'skip' => __('Skipped')];
        $statusTones = ['ok' => 'good', 'warn' => 'warn', 'fail' => 'crit', 'skip' => null];
        $linkFields = (array) ($clientAccess['link_fields'] ?? []);
        $linkErrors = collect(array_keys($linkFields))->contains(fn (string $name): bool => $errors->has($name));
    @endphp
    <section class="card" id="client-access" style="display:grid;gap:.8rem">
        <div class="card-header" style="margin:0"><h2>{{ __('Client access') }}</h2></div>

        @if ($clientAccess['check_url'])
            <p class="muted" style="margin:0">{{ __('Check that the client can open their control panel and use the server panel. Nothing is changed on the server.') }}</p>
            <form method="POST" action="{{ $clientAccess['check_url'] }}">
                @csrf
                <button class="btn btn-sm" type="submit"><x-icon name="shield" />{{ __('Test panel sign-in') }}</button>
            </form>

            @if (is_array($clientAccess['checks']))
                @php $summary = (array) $clientAccess['summary']; @endphp
                <p style="margin:0" role="status">
                    {{ __(':ok passed, :warn to check, :fail failed', ['ok' => $summary['ok'] ?? 0, 'warn' => $summary['warn'] ?? 0, 'fail' => $summary['fail'] ?? 0]) }}
                </p>
                <ul class="list-plain" style="display:grid;gap:10px;margin:0;padding:0;list-style:none">
                    @foreach ($clientAccess['checks'] as $check)
                        <li style="display:grid;gap:2px">
                            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px">
                                <x-pill :tone="$statusTones[$check['status']] ?? null">{{ $statusLabels[$check['status']] ?? $check['status'] }}</x-pill>
                                <strong>{{ $check['label'] }}</strong>
                            </div>
                            @if ($check['detail'] !== '')
                                <span class="muted" style="font-size:.85rem;overflow-wrap:anywhere">{{ $check['detail'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif

        @if ($clientAccess['link_url'] && $linkFields !== [])
            <details @if ($linkErrors) open @endif>
                <summary style="cursor:pointer;font-weight:600">{{ __('Link an existing account') }}</summary>
                <form method="POST" action="{{ $clientAccess['link_url'] }}" style="display:grid;gap:.7rem;margin-top:.7rem" data-confirm="{{ __('Link this service to that account? The service then controls it.') }}">
                    @csrf
                    <p class="muted" style="margin:0;font-size:.85rem">{{ __('For an account that was made by hand on the server, or moved from another billing system. The service status does not change; set it to Active yourself when it is ready.') }}</p>
                    @foreach ($linkFields as $name => $field)
                        @php
                            $id = 'link-'.$name;
                            $type = in_array($field['type'] ?? 'text', ['text', 'password', 'number', 'email', 'checkbox'], true) ? $field['type'] : 'text';
                            $help = is_string($field['help'] ?? null) && $field['help'] !== '' ? $field['help'] : null;
                        @endphp
                        @if ($type === 'checkbox')
                            <div class="field">
                                <label class="check" for="{{ $id }}">
                                    <input id="{{ $id }}" type="checkbox" name="{{ $name }}" value="1" @checked(old($name))>
                                    <span>{{ $field['label'] }}@if ($help)<br><span class="help">{{ $help }}</span>@endif</span>
                                </label>
                                @if ($errors->has($name))<p class="error">{{ $errors->first($name) }}</p>@endif
                            </div>
                        @else
                            <div class="field">
                                <label for="{{ $id }}">{{ $field['label'] }}@if ($field['required'] ?? false) <span aria-hidden="true">*</span>@endif</label>
                                <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" class="input" maxlength="255" autocomplete="{{ $type === 'password' ? 'new-password' : 'off' }}"
                                    @if ($type !== 'password') value="{{ old($name) }}" @endif
                                    @if ($field['required'] ?? false) required @endif
                                    @if ($errors->has($name)) aria-invalid="true" aria-describedby="{{ $id }}-error" @elseif ($help) aria-describedby="{{ $id }}-help" @endif>
                                @if ($errors->has($name))
                                    <p class="error" id="{{ $id }}-error">{{ $errors->first($name) }}</p>
                                @elseif ($help)
                                    <p class="help" id="{{ $id }}-help">{{ $help }}</p>
                                @endif
                            </div>
                        @endif
                    @endforeach
                    <div><button class="btn btn-sm btn-primary" type="submit"><x-icon name="plug" />{{ __('Link account') }}</button></div>
                </form>
            </details>
        @endif
    </section>
@endif
