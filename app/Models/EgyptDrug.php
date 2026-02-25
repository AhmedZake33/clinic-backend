<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EgyptDrug extends Model
{
    protected $table = 'egypt_drugs';

    protected $fillable = [
        'name',
        'price',
        'form',
        'company',
        'category',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];
}
