<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImportDownloadController extends Controller
{
    public function download(string $file): BinaryFileResponse
    {
        abort_unless(auth()->check(), 403);

        $path = storage_path('app/temp/' . basename($file));

        abort_unless(file_exists($path), 404, 'File not found or expired.');

        return response()->download($path, $file)->deleteFileAfterSend(true);
    }
}
