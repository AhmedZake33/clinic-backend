<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class Archive extends Model
{
    use HasFactory;

    public const TYPE_FOLDER = 0;
    public const TYPE_PAGE = 1;
    public const TYPE_FILE = 2;
    public const TYPE_TEXT = 3;
    public const TYPE_JSON = 4;
    public const TYPE_XML = 5;
    public const TYPE_URL = 6;

    public const FLAG_VISIBLE = 0x01;
    public const FLAG_WRITABLE = 0x02;

    protected $table = 'archive';

    protected $fillable = [
        'related_id',
        'version',
        'language',
        'short_name',
        'parent_id',
        'type',
        'archive_link',
        'order',
        'flags',
        'user_id',
        'title',
        'sub_title',
        'description',
        'extension',
        'application_type',
        'content_type',
        'document_type_id',
        'document_id',
        'uploading_stage',
        'size',
        'path',
        'search_text',
        'access_count',
    ];

    protected $casts = [
        'related_id' => 'integer',
        'version' => 'integer',
        'parent_id' => 'integer',
        'type' => 'integer',
        'order' => 'integer',
        'flags' => 'integer',
        'user_id' => 'integer',
        'document_type_id' => 'integer',
        'document_id' => 'integer',
        'uploading_stage' => 'integer',
        'size' => 'integer',
        'access_count' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->where('related_id', 0)
            ->orderByRaw('COALESCE(`order`, 2147483647)')
            ->orderBy('id');
    }

    public function related(): HasMany
    {
        return $this->hasMany(self::class, 'related_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'archive_users', 'archive_id', 'user_id')
            ->withPivot('access_mode');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(ArchiveRole::class, 'archive_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(ArchiveUpdate::class, 'archive_id');
    }

    public static function root(string $language = 'en'): self
    {
        $archive = new self();
        $archive->id = 0;
        $archive->parent_id = 0;
        $archive->related_id = 0;
        $archive->type = self::TYPE_FOLDER;
        $archive->title = 'Archive';
        $archive->language = $language;
        $archive->path = '';
        $archive->flags = self::FLAG_VISIBLE | self::FLAG_WRITABLE;
        $archive->exists = false;

        return $archive;
    }

    public function isRoot(): bool
    {
        return (int) $this->id === 0;
    }

    public function isFolder(): bool
    {
        return (int) $this->type === self::TYPE_FOLDER;
    }

    public function isFile(): bool
    {
        return (int) $this->type === self::TYPE_FILE;
    }

    public function name(): string
    {
        return $this->extension
            ? $this->title . '.' . $this->extension
            : $this->title;
    }

    public function diskPath(): string
    {
        if ($this->isRoot()) {
            return '';
        }

        $base = trim((string) $this->path, '/');
        $name = trim($this->name(), '/');

        return $base === '' ? $name : $base . '/' . $name;
    }

    public function parentDiskPath(): string
    {
        return trim((string) $this->path, '/');
    }

    public function sizeText(): string
    {
        $bytes = (int) $this->size;
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'TB') {
                return round($value, 2) . ' ' . $unit;
            }
            $value /= 1024;
        }

        return $bytes . ' B';
    }

    public function toTreeData(): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'related_id' => $this->related_id,
            'type' => $this->type,
            'title' => $this->title,
            'short_name' => $this->short_name,
            'language' => $this->language,
            'description' => $this->description,
            'sub_title' => $this->sub_title,
            'content_type' => $this->content_type,
            'extension' => $this->extension,
            'application_type' => $this->application_type,
            'size' => $this->size,
            'size_text' => $this->sizeText(),
            'path' => $this->diskPath(),
            'download_url' => $this->exists && $this->isFile() ? route('archive.download', ['archive' => $this->id]) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    public static function resolveParent(?int $parentId, ?string $language = null): self
    {
        if (!$parentId) {
            return self::root($language ?? app()->getLocale());
        }

        return self::query()->findOrFail($parentId);
    }

    public function refreshPathRecursively(): void
    {
        foreach ($this->children()->get() as $child) {
            $child->path = $this->diskPath();
            $child->save();
            $child->refreshPathRecursively();
        }
    }

    public static function sanitizeTitle(string $title): string
    {
        $clean = trim($title);
        $clean = preg_replace('/[\\\\\/]+/', '-', $clean) ?? $clean;
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;

        return $clean !== '' ? $clean : 'item';
    }

    public function nextSiblingOrder(): int
    {
        return (int) self::query()->where('parent_id', $this->id)->max('order') + 1;
    }

    public function siblingOrderForParent(): int
    {
        return (int) self::query()->where('parent_id', $this->parent_id)->max('order') + 1;
    }

    public function ensureUniqueTitle(string $title, ?int $exceptId = null): string
    {
        $candidate = self::sanitizeTitle($title);
        $base = $candidate;
        $counter = 1;

        while (
            self::query()
                ->where('parent_id', $this->id)
                ->where('title', $candidate)
                ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
                ->exists()
        ) {
            $candidate = $base . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }

    public static function createFolder(self $parent, array $attributes = []): self
    {
        $title = $parent->ensureUniqueTitle($attributes['title'] ?? 'Folder');

        $folder = new self();
        $folder->fill([
            'related_id' => 0,
            'version' => 0,
            'language' => $attributes['language'] ?? app()->getLocale(),
            'short_name' => $attributes['short_name'] ?? null,
            'parent_id' => $parent->id,
            'type' => self::TYPE_FOLDER,
            'order' => $parent->nextSiblingOrder(),
            'flags' => self::FLAG_VISIBLE | self::FLAG_WRITABLE,
            'user_id' => Auth::id(),
            'title' => $title,
            'sub_title' => $attributes['sub_title'] ?? null,
            'description' => $attributes['description'] ?? null,
            'content_type' => $attributes['content_type'] ?? 'folder',
            'size' => 0,
            'path' => $parent->diskPath(),
            'search_text' => Str::lower($title . ' ' . ($attributes['description'] ?? '')),
            'access_count' => 0,
        ]);
        $folder->save();

        Storage::disk('archive')->makeDirectory($folder->diskPath());

        return $folder->refresh();
    }

    public static function createFile(self $parent, UploadedFile $file, array $attributes = []): self
    {
        $originalTitle = $attributes['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $title = $parent->ensureUniqueTitle($originalTitle);
        $extension = strtolower($file->getClientOriginalExtension());

        $archive = new self();
        $archive->fill([
            'related_id' => 0,
            'version' => 0,
            'language' => $attributes['language'] ?? app()->getLocale(),
            'short_name' => $attributes['short_name'] ?? null,
            'parent_id' => $parent->id,
            'type' => self::TYPE_FILE,
            'order' => $parent->nextSiblingOrder(),
            'flags' => self::FLAG_VISIBLE | self::FLAG_WRITABLE,
            'user_id' => Auth::id(),
            'title' => $title,
            'sub_title' => $attributes['sub_title'] ?? null,
            'description' => $attributes['description'] ?? null,
            'extension' => $extension,
            'application_type' => $file->getMimeType(),
            'content_type' => $attributes['content_type'] ?? null,
            'size' => (int) $file->getSize(),
            'path' => $parent->diskPath(),
            'search_text' => Str::lower($title . ' ' . ($attributes['description'] ?? '')),
            'access_count' => 0,
        ]);
        $archive->save();

        Storage::disk('archive')->putFileAs($parent->diskPath(), $file, $archive->name());

        return $archive->refresh();
    }

    public function replaceFile(UploadedFile $file): self
    {
        if (!$this->isFile()) {
            throw new \RuntimeException('Only file archives can be replaced.');
        }

        if (Storage::disk('archive')->exists($this->diskPath())) {
            Storage::disk('archive')->delete($this->diskPath());
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $this->extension = $extension;
        $this->application_type = $file->getMimeType();
        $this->size = (int) $file->getSize();
        $this->save();

        Storage::disk('archive')->putFileAs($this->parentDiskPath(), $file, $this->name());

        return $this->refresh();
    }

    public function renameArchive(string $newTitle): self
    {
        $newTitle = self::sanitizeTitle($newTitle);
        if ($newTitle === $this->title) {
            return $this;
        }

        $targetTitle = self::query()
            ->where('parent_id', $this->parent_id)
            ->where('title', $newTitle)
            ->where('id', '!=', $this->id)
            ->exists()
            ? $newTitle . '-' . $this->id
            : $newTitle;

        $oldPath = $this->diskPath();
        $this->title = $targetTitle;
        $this->search_text = Str::lower($this->title . ' ' . ($this->description ?? ''));
        $this->save();

        $newPath = $this->diskPath();
        if ($oldPath !== $newPath && Storage::disk('archive')->exists($oldPath)) {
            Storage::disk('archive')->move($oldPath, $newPath);
        }

        if ($this->isFolder()) {
            $this->refreshPathRecursively();
        }

        return $this->refresh();
    }

    public function deleteArchive(): void
    {
        if (Storage::disk('archive')->exists($this->diskPath())) {
            if ($this->isFolder()) {
                Storage::disk('archive')->deleteDirectory($this->diskPath());
            } else {
                Storage::disk('archive')->delete($this->diskPath());
            }
        }

        $this->children()->get()->each->deleteArchive();
        $this->roles()->delete();
        $this->updates()->delete();
        $this->users()->detach();
        $this->delete();
    }

    public function downloadResponse(): BinaryFileResponse
    {
        abort_unless($this->isFile(), 404, 'Archive item is not a file.');
        abort_unless(Storage::disk('archive')->exists($this->diskPath()), 404, 'File not found.');

        $this->increment('access_count');

        return response()->download(Storage::disk('archive')->path($this->diskPath()), $this->name());
    }
}