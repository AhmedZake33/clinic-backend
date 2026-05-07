<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Specialization extends Model
{
    protected $fillable = [
        'name',
        'name_en',
        'color',
        'features',
    ];

    protected $casts = [
        'features' => 'array',
    ];

    public function doctors()
    {
        return $this->hasMany(User::class, 'specialization_id');
    }
}
