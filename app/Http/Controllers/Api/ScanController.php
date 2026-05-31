<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ScanController extends Controller
{
    use ApiResponse;

    private const PROMPTS = [
        'do' => 'Extract these fields from this Delivery Order document as JSON. Return null for unreadable fields. Include a _confidence key (0.0–1.0) per field. Fields: do_number, order_date (YYYY-MM-DD), origin, destination, cylinder_type (one of: 3kg, 5.5kg, 12kg, 50kg), quantity (integer), expedition_name, container_number, eta (YYYY-MM-DD).',
        'shipment' => 'Extract from this Surat Jalan document as JSON with _confidence per field. Fields: sj_number, date (YYYY-MM-DD), driver_name, vehicle_plate, quantity_sent (integer), notes.',
        'bast' => 'Extract from this BAST (Berita Acara Serah Terima) document as JSON with _confidence per field. Fields: do_reference, received_date (YYYY-MM-DD), quantity_received (integer), receiver_name, notes.',
        'stock_form' => 'Extract daily stock figures from this form as JSON with _confidence per field. Fields: date (YYYY-MM-DD), qty_full_3kg, qty_empty_3kg, qty_damaged_3kg, qty_full_5_5kg, qty_empty_5_5kg, qty_damaged_5_5kg, qty_full_12kg, qty_empty_12kg, qty_damaged_12kg, qty_full_50kg, qty_empty_50kg, qty_damaged_50kg. All quantity fields are integers.',
    ];

    public function scan(Request $request): JsonResponse
    {
        $request->validate([
            'image'         => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
            'document_type' => ['required', 'in:do,shipment,bast,stock_form'],
        ]);

        $apiKey = config('services.anthropic.key');
        if (! $apiKey) {
            return $this->error('Document scanner not configured. Please set ANTHROPIC_API_KEY.', 503);
        }

        $documentType = $request->input('document_type');
        $prompt       = self::PROMPTS[$documentType];

        $file     = $request->file('image');
        $mimeType = $file->getMimeType();
        $base64   = base64_encode(file_get_contents($file->getRealPath()));

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
            'model'      => 'claude-opus-4-7',
            'max_tokens' => 1024,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    [
                        'type'   => 'image',
                        'source' => [
                            'type'       => 'base64',
                            'media_type' => $mimeType,
                            'data'       => $base64,
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => $prompt . ' Return ONLY valid JSON, no markdown fences.',
                    ],
                ],
            ]],
        ]);

        if (! $response->successful()) {
            return $this->error('Document scan service error. Please try again or fill manually.', 502);
        }

        $raw  = $response->json('content.0.text', '{}');
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return $this->error("Couldn't read the document clearly. Please fill manually.", 422);
        }

        // Separate fields from confidence scores
        $fields     = collect($data)->reject(fn ($v, $k) => str_ends_with($k, '_confidence'))->all();
        $confidence = collect($data)->filter(fn ($v, $k) => str_ends_with($k, '_confidence'))
            ->mapWithKeys(fn ($v, $k) => [str_replace('_confidence', '', $k) => $v])->all();

        // Low-confidence fields
        $lowConfidence = collect($confidence)->filter(fn ($c) => $c < 0.5)->keys()->values()->all();

        // Store image for audit trail
        $path = $file->store('scans/' . now()->format('Y/m'), 'local');

        return $this->success([
            'document_type'  => $documentType,
            'fields'         => $fields,
            'confidence'     => $confidence,
            'low_confidence' => $lowConfidence,
            'scan_image_path'=> $path,
            'can_auto_fill'  => count($lowConfidence) === 0,
        ]);
    }
}
