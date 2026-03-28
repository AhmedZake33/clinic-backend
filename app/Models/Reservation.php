<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use HasFactory;

    protected $appends = [
        'completion_files',
    ];

    protected $fillable = [
        'client_id',
        'doctor_id',
        'archive_id',
        'created_by',
        'appointment_date',
        'status',
        'notes',
        'diagnosis',
        'treatment',
        'current_procedures',
        'procedure_notes',
        'next_procedures',
        'requires_xray',
        'xray_notes',
        'requires_lab',
        'lab_notes',
        'completed_at',
        'checked_in_at',
        'waiting_number',
    ];

    protected $casts = [
        'appointment_date' => 'datetime:Y-m-d H:i:s',
        'completed_at' => 'datetime:Y-m-d H:i:s',
        'checked_in_at' => 'datetime:Y-m-d H:i:s',
        'requires_xray' => 'boolean',
        'requires_lab' => 'boolean',
        'waiting_number' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function archive()
    {
        return $this->belongsTo(Archive::class, 'archive_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function financial()
    {
        return $this->hasOne(Financial::class);
    }

    public function getCompletionFilesAttribute()
    {
        if (!$this->relationLoaded('archive') || !$this->archive) {
            return [];
        }

        $children = $this->archive->relationLoaded('children')
            ? $this->archive->children
            : collect();

        return $children
            ->where('type', Archive::TYPE_FILE)
            ->values()
            ->map(function (Archive $file) {
                return [
                    'id' => $file->id,
                    'title' => $file->title,
                    'file_name' => $file->name(),
                    'size' => $file->size,
                    'size_text' => $file->sizeText(),
                    'content_type' => $file->content_type,
                ];
            })
            ->all();
    }
}
