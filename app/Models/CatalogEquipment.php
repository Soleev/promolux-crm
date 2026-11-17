<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogEquipment extends Model
{
    use Concerns\Audited;

    protected $table = 'catalog_equipment';

    protected $guarded = ['id'];
}
