{{--
    Service page → the server module's own card (HasStaffPanel), from ServiceStaffController::card():
    staffCard is null, or {title, rows, actions, notice, failed, needs_two_factor}; with
    needs_two_factor the module was not asked and only the way to two-factor login shows. Rows are plain
    text, shown escaped. Each action posts to admin.services.staff-action with its own fields; a
    field error comes back in the "staff_{action}" error bag.

    orderDetails: what the client entered in the module's order form fields, as {label, value};
    a null value is secret (or no longer declared) and is never shown.
--}}
@if (! empty($orderDetails))
    <section class="card" id="order-details" style="display:grid;gap:.6rem">
        <div class="card-header" style="margin:0"><h2>{{ __('Order details') }}</h2></div>
        <dl class="dl">
            @foreach ($orderDetails as $detail)
                <dt>{{ $detail['label'] }}</dt>
                <dd>
                    @if ($detail['value'] === null)
                        <span class="muted">{{ __('Saved, hidden') }}</span>
                    @else
                        <span class="mono" dir="ltr" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $detail['value'] }}</span>
                    @endif
                </dd>
            @endforeach
        </dl>
    </section>
@endif

@if (! empty($staffCard))
    <section class="card" id="module-staff" style="display:grid;gap:.8rem">
        <div class="card-header" style="margin:0"><h2>{{ $staffCard['title'] }}</h2></div>

        @if ($staffCard['notice'])
            <p class="muted" style="margin:0" @if ($staffCard['failed']) role="alert" @endif>{{ $staffCard['notice'] }}</p>
        @endif

        @if ($staffCard['rows'] !== [])
            <dl class="dl">
                @foreach ($staffCard['rows'] as $row)
                    <dt>{{ $row['label'] }}</dt><dd style="overflow-wrap:anywhere">{{ $row['value'] }}</dd>
                @endforeach
            </dl>
        @endif

        @if ($staffCard['needs_two_factor'])
            @if (auth('admin')->user()?->hasTwoFactorEnabled())
                <p class="muted" style="margin:0">{{ __('Enter a code from your authenticator app to use server module tools.') }} <a href="{{ route('admin.two-factor.step-up') }}">{{ __('Continue') }}</a></p>
            @else
                <p class="muted" style="margin:0">{{ __('Turn on two-factor login in your profile to use server module tools.') }} <a href="{{ route('admin.profile.edit') }}">{{ __('Your profile') }}</a></p>
            @endif
        @endif

        @foreach ($staffCard['actions'] as $action)
            @php
                $fields = $action->checkedFields();
                $bag = $errors->getBag('staff_'.$action->key);
            @endphp
            <form method="POST" action="{{ route('admin.services.staff-action', [$service, $action->key]) }}" style="display:grid;gap:.6rem" @if ($action->confirm) data-confirm="{{ $action->confirm }}" @endif>
                @csrf
                @foreach ($fields as $name => $field)
                    @php $id = 'staff-'.$action->key.'-'.$name; @endphp
                    @if ($field['type'] === 'checkbox')
                        <label class="check"><input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="1" @checked(old($name))> <span>{{ $field['label'] }}</span></label>
                    @else
                        <div class="field">
                            <label for="{{ $id }}">{{ $field['label'] }}@if ($field['required']) <span aria-hidden="true">*</span>@endif</label>
                            @if ($field['type'] === 'select')
                                <select id="{{ $id }}" name="{{ $name }}" class="select" @required($field['required'])>
                                    @unless ($field['required'])<option value="">{{ __('None') }}</option>@endunless
                                    @foreach ($field['options'] as $optionValue => $optionLabel)
                                        <option value="{{ $optionValue }}" @selected((string) old($name) === (string) $optionValue)>{{ $optionLabel }}</option>
                                    @endforeach
                                </select>
                            @elseif ($field['type'] === 'password')
                                {{-- Never filled in, also not after an error. --}}
                                <input id="{{ $id }}" name="{{ $name }}" type="password" class="input" maxlength="{{ $field['max'] }}" autocomplete="new-password" @required($field['required'])>
                            @else
                                <input id="{{ $id }}" name="{{ $name }}" type="{{ $field['type'] === 'number' ? 'number' : 'text' }}" class="input" @if ($field['type'] !== 'number') maxlength="{{ $field['max'] }}" @endif value="{{ old($name) }}" autocomplete="off" @required($field['required'])>
                            @endif
                            @if ($bag->has($name))
                                <p class="error">{{ $bag->first($name) }}</p>
                            @elseif ($field['help'])
                                <p class="help">{{ $field['help'] }}</p>
                            @endif
                        </div>
                    @endif
                @endforeach
                <div><button class="btn btn-sm {{ $action->danger ? 'btn-danger' : '' }}" type="submit">{{ $action->label }}</button></div>
            </form>
        @endforeach
    </section>
@endif
