<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Services\CrmAccess;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CrmDocumentController extends Controller
{
    public function __invoke(string $type, int $id): StreamedResponse
    {
        $model = match ($type) {
            'order' => Order::class,'payment' => Payment::class,default => abort(404)
        };
        $record = CrmAccess::query($model)->findOrFail($id);
        $path = $type === 'order' ? $record->attachment : $record->document;
        abort_unless($path && str_starts_with($path, 'documents/') && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, null, ['Cache-Control' => 'private, no-store']);
    }
}
