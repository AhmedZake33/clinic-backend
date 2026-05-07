<?php

namespace App\Http\Controllers;

use App\Models\Feature;
use Illuminate\Http\Request;

class FeatureController extends Controller
{
    public function index()
    {
        return Feature::orderBy('sort_order')->orderBy('id')->get();
    }
}
