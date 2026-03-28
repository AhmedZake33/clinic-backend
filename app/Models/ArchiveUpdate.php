<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArchiveUpdate extends Model
{
    use HasFactory;

    protected $table = 'archive_updates';

    protected $fillable = [
        'user_id',
        'archive_id',
        'main_archive_id',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'archive_id' => 'integer',
        'main_archive_id' => 'integer',
    ];

    public function archive(): BelongsTo
    {
        return $this->belongsTo(Archive::class, 'archive_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}