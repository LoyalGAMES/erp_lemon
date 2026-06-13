<form method="POST" action="{{ route('documents.post', $document) }}">
    @csrf
    <button class="button" type="submit">Zaksięguj</button>
</form>
