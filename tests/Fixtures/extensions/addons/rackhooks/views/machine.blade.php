<section class="card" data-rackhooks-card>
    <h2>Switch port</h2>
    <p>Port: {{ $port }}</p>
    @if ($canEdit)
        <form method="POST" action="{{ route('admin.hardware-addons.rackhooks.port', $machine) }}">@csrf<input name="port"></form>
    @endif
</section>
