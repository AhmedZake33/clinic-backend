<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArchiveRole extends Model
{
    use HasFactory;

    protected $table = 'archive_roles';

    public $timestamps = false;

    protected $fillable = [
        'archive_id',
        'role_id',
        'access_mode',
    ];

    protected $casts = [
        'archive_id' => 'integer',
        'role_id' => 'integer',
        'access_mode' => 'integer',
    ];

    public function archive(): BelongsTo
    {
        return $this->belongsTo(Archive::class, 'archive_id');
    }
}