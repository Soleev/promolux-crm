<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogSupplier extends Model
{
    use Concerns\Audited;

    protected $table = 'catalog_suppliers';

    protected $guarded = ['id'];
}
