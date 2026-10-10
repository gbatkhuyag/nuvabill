<div class="panel-fake-notes" data-panel-fake-notes>
    @foreach ((array) ($data['notes'] ?? []) as $note)
        <p>{{ is_string($note) ? $note : '' }}</p>
    @endforeach
</div>
