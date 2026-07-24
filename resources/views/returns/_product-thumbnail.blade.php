@php
    $returnImageUrl = trim((string) ($imageUrl ?? ''));
    $returnThumbnailUrl = trim((string) ($thumbnailUrl ?? $returnImageUrl));
    $returnImageTitle = trim((string) ($imageTitle ?? 'Podgląd produktu'));
@endphp

@if ($returnImageUrl !== '')
    <button
        class="return-product-thumb return-product-thumb-button"
        type="button"
        data-return-image-preview="{{ $returnImageUrl }}"
        data-return-image-title="{{ $returnImageTitle }}"
        aria-label="Powiększ zdjęcie: {{ $returnImageTitle }}"
    >
        <img src="{{ $returnThumbnailUrl }}" alt="{{ $returnImageTitle }}" loading="lazy" decoding="async" referrerpolicy="no-referrer">
    </button>
@else
    <div class="return-product-thumb" aria-label="Brak zdjęcia: {{ $returnImageTitle }}">Foto</div>
@endif
