@extends('theme::layouts.app')

@section('title', __('Connect to the Nuvabill marketplace'))

@section('content')
    <div class="auth-wrap">
        <div class="auth-box">
            <h1 style="font-size:1.5rem;overflow-wrap:anywhere">{{ __('Connect :site to the Nuvabill marketplace?', ['site' => $site]) }}</h1>
            <form method="POST" action="{{ route('marketplace.connect.store') }}" class="card" style="display:grid;gap:1rem">
                @csrf
                <input type="hidden" name="site" value="{{ $site }}">
                <input type="hidden" name="state" value="{{ $state }}">
                @if ($ip)<input type="hidden" name="ip" value="{{ $ip }}">@endif
                <p style="margin:0">{{ __('The marketplace calls :site to confirm that you run it and gives it its own key. Its server can then reach the marketplace even when Cloudflare checks for bots. It is free.', ['site' => $site]) }}</p>
                <p class="muted" style="margin:0;font-size:.88rem">{{ __('Only continue if you clicked "Connect to marketplace" on this site\'s Marketplace page just now.') }}</p>
                <x-captcha form="marketplace_connect" />
                <button class="btn btn-primary btn-block" type="submit">{{ __('Connect') }}</button>
            </form>
        </div>
    </div>
@endsection
