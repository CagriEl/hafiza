<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityCatalog extends Model
{
    protected $fillable = [
        'mudurluk',
        'faaliyet_kodu',
        'faaliyet_ailesi',
        'kategori',
        'kapsam',
        'olcu_birimi',
        'kpi_sla',
    ];
}
