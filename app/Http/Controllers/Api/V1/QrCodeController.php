<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Artwork;
use App\Models\ArtLot;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\Response;

/**
 * Phase 96 — QR code generation for artworks and art lots.
 *
 * Returns an SVG QR code pointing to the public detail URL.
 * Intended for gallery labels and printed catalogues.
 */
final class QrCodeController extends Controller
{
    /**
     * GET /api/v1/artworks/{artwork}/qr
     */
    public function artwork(Artwork $artwork): Response
    {
        if ($artwork->status !== 'listed') {
            abort(404);
        }

        $url = rtrim(config('app.url'), '/') . "/artworks/{$artwork->id}";
        $svg = $this->generateSvg($url);

        return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
    }

    /**
     * GET /api/v1/lots/{artLot}/qr
     */
    public function lot(ArtLot $artLot): Response
    {
        if ($artLot->status !== 'active') {
            abort(404);
        }

        $url = rtrim(config('app.url'), '/') . "/lots/{$artLot->id}";
        $svg = $this->generateSvg($url);

        return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
    }

    private function generateSvg(string $data): string
    {
        $options = new QROptions([
            'outputType'   => QRCode::OUTPUT_MARKUP_SVG,
            'outputBase64' => false,
        ]);
        return (new QRCode($options))->render($data);
    }
}
