<?php

namespace App\Http\Controllers;

use App\Services\Money;
use App\Services\Report;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CrmExportController extends Controller
{
    public function __invoke(Request $request, Report $report): StreamedResponse
    {
        abort_unless($request->user()->active && ! in_array($request->user()->role, ['cashier', 'designer'], true), 403);
        $data = $request->validate(['from' => 'nullable|date', 'until' => 'nullable|date|after_or_equal:from', 'manager' => 'nullable|integer']);
        $rows = $report->register($request->user(), $data['from'] ?? null, $data['until'] ?? null, isset($data['manager']) ? (int) $data['manager'] : null);

        return response()->streamDownload(function () use ($rows) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['Месяц', 'Оплата', 'Заказ', 'Получатель', 'Начисление', 'Валюта', 'Начислено', 'Погашено', 'Остаток'], ';', '"', '');
            foreach ($rows as $row) {
                foreach (['accrued', 'paid', 'remaining'] as $key) {
                    $row[$key] = Money::format($row[$key]);
                }
                $row = array_map(fn ($v) => is_string($v) && preg_match('/^[=+@\\-\\t\\r]/', $v) ? "'".$v : $v, $row);
                fputcsv($file, $row, ';', '"', '');
            }
            fclose($file);
        }, 'promolux-register.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
