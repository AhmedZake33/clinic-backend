<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feature extends Model
{
    protected $fillable = ['key', 'name', 'name_en', 'icon', 'sort_order'];
}
