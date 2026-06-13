<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Products\ProductImageThumbnailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductImageThumbnailController extends Controller
{
    public function __invoke(Request $request, ProductImageThumbnailService $thumbnails): BinaryFileResponse|RedirectResponse
    {
        $encodedSource = (string) $request->query('src', '');
        $width = (int) $request->query('w', 116);
        $height = (int) $request->query('h', 144);
        $signature = (string) $request->query('sig', '');
        $source = $thumbnails->sourceFromSignedRequest($encodedSource, $width, $height, $signature);

        abort_if($source === null, 404);

        $path = $thumbnails->cachedThumbnailPath($source, $width, $height);

        if ($path === null) {
            return redirect()->away($source);
        }

        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
