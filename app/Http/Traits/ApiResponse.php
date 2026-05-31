<?php

namespace App\Http\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

trait ApiResponse
{
    protected function success(mixed $data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'message' => $message], $status);
    }

    protected function paginated(LengthAwarePaginator $paginator, callable $transform = null): JsonResponse
    {
        $items = $transform ? $paginator->getCollection()->map($transform) : $paginator->getCollection();

        return response()->json([
            'data' => $items,
            'meta' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
            'message' => 'OK',
        ]);
    }

    protected function created(mixed $data, string $message = 'Created.'): JsonResponse
    {
        return response()->json(['data' => $data, 'message' => $message], 201);
    }

    protected function noContent(string $message = 'Deleted.'): JsonResponse
    {
        return response()->json(['message' => $message]);
    }

    protected function error(string $message, int $status = 400, array $errors = []): JsonResponse
    {
        $body = ['message' => $message];
        if ($errors) {
            $body['errors'] = $errors;
        }
        return response()->json($body, $status);
    }

    protected function forbidden(string $message = 'Unauthorized.'): JsonResponse
    {
        return response()->json(['message' => $message], 403);
    }
}
