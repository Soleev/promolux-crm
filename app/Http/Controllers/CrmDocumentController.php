<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Services\CrmAccess;
use App\Services\RefundAccess;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CrmDocumentController extends Controller
{
    public function __invoke(string $type, int $id): StreamedResponse
    {
        $path = match ($type) {
            'order' => CrmAccess::query(Order::class)->findOrFail($id)->attachment,
            'payment' => CrmAccess::query(Payment::class)->findOrFail($id)->document,
            'refund' => $this->refundDocument($id),
            default => abort(404),
        };
        $directory = $type === 'refund' ? 'refund-documents/'.$id.'/' : 'documents/';
        abort_unless($path && str_starts_with($path, $directory), 404);
        abort_if(in_array('..', explode('/', str_replace('\\', '/', $path)), true), 404);
        abort_if($type === 'refund' && dirname($path) !== rtrim($directory, '/'), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, null, ['Cache-Control' => 'private, no-store']);
    }

    private function refundDocument(int $id): ?string
    {
        abort_unless(RefundAccess::canRequest(auth()->user()), 404);

        return RefundAccess::query()->findOrFail($id)->document;
    }
}
