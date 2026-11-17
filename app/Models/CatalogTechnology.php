<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogTechnology extends Model
{
    use Concerns\Audited;

    protected $table = 'catalog_technologies';

    protected $guarded = ['id'];
}
