<x-layouts.admin :title="__('Two-factor check')">
    <div class="page-head">
        <div>
            <h1>{{ __('Two-factor check') }}</h1>
            <p>{{ __('Server module tools reach many clients\' servers. Enter the 6-digit code from your authenticator app to continue.') }}</p>
        </div>
    </div>

    <section class="card" style="display:grid;gap:1rem;max-width:28rem" x-data="{ recovery: false }">
        <form method="POST" action="{{ route('admin.two-factor.step-up') }}" style="display:grid;gap:1rem">
            @csrf
            <template x-if="! recovery">
                <x-input name="code" :label="__('Code')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus />
            </template>
            <template x-if="recovery">
                <x-input name="recovery_code" :label="__('Recovery code')" :help="__('One of the codes you saved when you turned on two-factor login.')" autocomplete="off" />
            </template>
            <button class="btn btn-primary" type="submit">{{ __('Continue') }}</button>
        </form>
        <button type="button" class="btn btn-ghost btn-sm" @click="recovery = ! recovery"
            x-text="recovery ? @js(__('Use a code from my app')) : @js(__('I lost my phone: use a recovery code'))"></button>
    </section>
</x-layouts.admin>
