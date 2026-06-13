@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@section('content')
    <div class="page-toolbar">
        <div class="inline-actions">
            <a class="button secondary" href="{{ route('documents.index') }}">Wróć do dokumentów</a>
        </div>
        <span class="status blue">Szkic</span>
    </div>

    @include('documents._form', [
        'action' => route('documents.store'),
        'method' => 'POST',
        'submitLabel' => 'Utwórz szkic',
    ])
@endsection
