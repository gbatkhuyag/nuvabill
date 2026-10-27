<x-layouts.admin :title="__('Hook fake')">
    <div class="page-head"><div><h1>Hook fake nodes</h1><p>{{ $note }}</p></div></div>
    <form method="POST" action="{{ route(\App\Extensions\Servers\Staff\ModuleAdmin::routeName('hookfake', 'maintenance')) }}">
        @csrf
        <button class="btn" type="submit">Maintenance</button>
    </form>
</x-layouts.admin>
