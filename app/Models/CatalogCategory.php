<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogCategory extends Model
{
    use Concerns\Audited;

    protected $table = 'catalog_categories';

    protected $guarded = ['id'];
}
