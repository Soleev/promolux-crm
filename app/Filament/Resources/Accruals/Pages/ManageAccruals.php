<?php

namespace App\Filament\Resources\Accruals\Pages;

use App\Filament\Resources\Accruals\AccrualResource;
use App\Services\AccrualOverview;
use App\Services\Money;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

class ManageAccruals extends ManageRecords
{
    protected static string $resource = AccrualResource::class;

    protected string $view = 'filament.pages.accrual-overview';

    public string $orderSearch = '';

    public ?int $detailOrder = null;

    public ?int $detailBeneficiary = null;

    public string $detailCurrency = 'UZS';

    protected function getHeaderActions(): array
    {
        return [];
    }

    #[Unexposed]
    public function table(Table $table): Table
    {
        if (auth()->user()->role === 'owner') {
            return parent::table($table);
        }

        return $table->query(AccrualResource::getEloquentQuery()->whereRaw('1 = 0'))->columns([])->recordActions([]);
    }

    public function updatedOrderSearch(): void
    {
        $this->resetPage('ordersPage');
        $this->closeDetails();
    }

    public function showPayments(int $orderId, int $beneficiaryId, string $currency): void
    {
        app(AccrualOverview::class)->payments(auth()->user(), $orderId, $beneficiaryId, $currency);
        $this->detailOrder = $orderId;
        $this->detailBeneficiary = $beneficiaryId;
        $this->detailCurrency = $currency;
        unset($this->paymentDetails);
    }

    public function closeDetails(): void
    {
        $this->detailOrder = null;
        $this->detailBeneficiary = null;
        unset($this->paymentDetails);
    }

    #[Computed]
    public function orderAccruals(): LengthAwarePaginator
    {
        return app(AccrualOverview::class)->orders(auth()->user(), $this->orderSearch)->paginate(10, pageName: 'ordersPage');
    }

    #[Computed]
    public function personalSummary(): Collection
    {
        return app(AccrualOverview::class)->summary(auth()->user());
    }

    #[Computed]
    public function overallSummary(): Collection
    {
        abort_unless(in_array(auth()->user()->role, ['owner', 'rop'], true), 403);

        return app(AccrualOverview::class)->fundingSummary(auth()->user());
    }

    #[Unexposed]
    public function formatAmount(int $minor, string $currency): string
    {
        return str_replace(' сум', ' '.($currency === 'UZS' ? 'сум' : $currency), Money::display($minor));
    }

    #[Computed]
    public function paymentDetails(): Collection
    {
        return $this->detailOrder === null || $this->detailBeneficiary === null ? collect()
            : app(AccrualOverview::class)->payments(auth()->user(), $this->detailOrder, $this->detailBeneficiary, $this->detailCurrency);
    }
}
