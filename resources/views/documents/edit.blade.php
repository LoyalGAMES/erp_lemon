@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@section('content')
    <div class="page-toolbar">
        <div class="inline-actions">
            <a class="button secondary" href="{{ route('documents.show', $document) }}">Wróć do dokumentu</a>
            <a class="button secondary" href="{{ route('documents.index') }}">Lista dokumentów</a>
        </div>
        <span class="status blue">Szkic</span>
    </div>

    @include('documents._form', [
        'action' => route('documents.update', $document),
        'method' => 'PUT',
        'submitLabel' => 'Zapisz szkic',
    ])
@endsection
