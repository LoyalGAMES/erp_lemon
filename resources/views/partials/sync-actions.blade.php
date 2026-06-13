@if ($item->status === 'failed')
    <form method="POST" action="{{ route('sync.retry', $item) }}">
        @csrf
        <button class="button" type="submit">Ponów</button>
    </form>
@elseif ($item->status === 'success')
    <span class="status">Wysłane</span>
@elseif ($item->status === 'running')
    <span class="status blue">W toku</span>
@else
    <span class="muted">Oczekuje</span>
@endif
