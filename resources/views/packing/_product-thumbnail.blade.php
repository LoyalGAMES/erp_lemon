@php
    $packingImageUrl = trim((string) ($imageUrl ?? ''));
    $packingThumbnailUrl = trim((string) ($thumbnailUrl ?? $packingImageUrl));
    $packingImageTitle = trim((string) ($imageTitle ?? $imageAlt ?? 'Podgląd produktu'));
    $packingImageAlt = trim((string) ($imageAlt ?? $packingImageTitle));
@endphp

@if ($packingImageUrl !== '')
    <button
        class="product-thumb product-thumb-button"
        type="button"
        data-packing-image-preview="{{ $packingImageUrl }}"
        data-packing-image-title="{{ $packingImageTitle }}"
        aria-label="Powiększ zdjęcie: {{ $packingImageTitle }}"
    >
        <img src="{{ $packingThumbnailUrl }}" alt="{{ $packingImageAlt }}" loading="lazy" decoding="async" referrerpolicy="no-referrer">
    </button>
@else
    <div class="product-thumb" aria-label="Brak zdjęcia: {{ $packingImageTitle }}">Foto</div>
@endif
