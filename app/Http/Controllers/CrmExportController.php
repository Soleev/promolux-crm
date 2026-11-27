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
        abort_unless($request->user()->active && $request->user()->role === 'owner', 403);
        $data = $request->validate(['from' => 'nullable|date', 'until' => 'nullable|date|after_or_equal:from', 'manager' => 'nullable|integer', 'type' => 'nullable|in:refunds']);
        $options = [$request->user(), $data['from'] ?? null, $data['until'] ?? null, isset($data['manager']) ? (int) $data['manager'] : null];
        $refunds = ($data['type'] ?? null) === 'refunds';
        $rows = $refunds ? $report->refundRegister(...$options) : $report->register(...$options);
        $headings = $refunds ? ['Дата возврата', 'Возврат', 'Заказ', 'Источник', 'Способ', 'Валюта', 'Сумма']
            : ['Месяц', 'Оплата', 'Заказ', 'Получатель', 'Начисление', 'Валюта', 'Плательщик', 'На начало', 'Начислено', 'Выплачено', 'Сторно возвратов', 'Погашение долга', 'Списание долга', 'На конец'];

        return response()->streamDownload(function () use ($rows, $headings, $refunds, $data) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, $headings, ';', '"', '');
            foreach ($rows as $row) {
                foreach (['order', 'number', 'beneficiary', 'currency', 'kind', 'payer'] as $key) {
                    if (isset($row[$key]) && preg_match('/^[=+@\\-\\t\\r]/', $row[$key])) {
                        $row[$key] = "'".$row[$key];
                    }
                }
                if (! $refunds) {
                    foreach (['opening', 'accrued', 'paid', 'storno', 'repaid', 'written_off', 'remaining'] as $key) {
                        $row[$key] = Money::format($row[$key]);
                    }
                }
                fputcsv($file, $row, ';', '"', '');
            }
            if (! $refunds && ! empty($data['manager'])) {
                fputcsv($file, ['Частичный баланс: непривязанные погашения и списания исключены фильтром менеджера.'], ';', '"', '');
            }
            fclose($file);
        }, $refunds ? 'promolux-refunds.csv' : 'promolux-register.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
