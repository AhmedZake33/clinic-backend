<?php

namespace App\Http\Controllers;

use App\Models\Archive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ArchiveController extends Controller
{
    public function archives(Request $request): JsonResponse
    {
        $request->validate([
            'parent_id' => 'nullable|integer',
            'language' => 'nullable|string|max:2',
            'type' => 'nullable|integer',
            'content_type' => 'nullable|string|max:128',
            'keywords' => 'nullable|string|max:255',
        ]);

        $parent = Archive::resolveParent($request->integer('parent_id'), $request->input('language'));

        $query = Archive::query()
            ->where('parent_id', $parent->id)
            ->where('related_id', 0)
            ->orderByRaw('COALESCE(`order`, 2147483647)')
            ->orderBy('id');

        if ($request->filled('type')) {
            $query->where('type', $request->integer('type'));
        }

        if ($request->filled('content_type')) {
            $query->where('content_type', $request->string('content_type')->toString());
        }

        if ($request->filled('keywords')) {
            $keyword = '%' . $request->string('keywords')->toString() . '%';
            $query->where(function ($builder) use ($keyword) {
                $builder
                    ->where('title', 'like', $keyword)
                    ->orWhere('description', 'like', $keyword)
                    ->orWhere('short_name', 'like', $keyword)
                    ->orWhere('content_type', 'like', $keyword);
            });
        }

        $archives = $query->paginate((int) $request->input('per_page', 20));
        $archives->getCollection()->transform(fn (Archive $archive) => $archive->toTreeData());

        return response()->json([
            'parent' => $parent->toTreeData(),
            'path' => $this->parentPath($parent),
            'data' => $archives->items(),
            'current_page' => $archives->currentPage(),
            'last_page' => $archives->lastPage(),
            'per_page' => $archives->perPage(),
            'total' => $archives->total(),
        ]);
    }

    public function show(Archive $archive): JsonResponse
    {
        return response()->json($archive->toTreeData());
    }

    public function put(Request $request, ?Archive $archive = null): JsonResponse
    {
        $request->validate([
            'parent_id' => 'nullable|integer',
            'title' => 'required|string|max:255',
            'short_name' => 'nullable|string|max:32',
            'description' => 'nullable|string',
            'sub_title' => 'nullable|string|max:512',
            'content_type' => 'nullable|string|max:128',
            'language' => 'nullable|string|max:2',
            'type' => 'nullable|integer',
        ]);

        if ($archive) {
            $archive->fill($request->only(['short_name', 'description', 'sub_title', 'content_type', 'language']));
            $archive->save();
            $archive->renameArchive($request->string('title')->toString());

            return response()->json($archive->fresh()->toTreeData());
        }

        $parent = Archive::resolveParent($request->integer('parent_id'), $request->input('language'));
        $type = $request->input('type', Archive::TYPE_FOLDER);

        if ((int) $type !== Archive::TYPE_FOLDER) {
            return response()->json(['message' => 'Only folder creation is supported via this endpoint.'], 422);
        }

        $folder = Archive::createFolder($parent, $request->only([
            'title',
            'short_name',
            'description',
            'sub_title',
            'content_type',
            'language',
        ]));

        return response()->json($folder->toTreeData(), 201);
    }

    public function upload(Request $request, ?Archive $archive = null): JsonResponse
    {
        $request->validate([
            'parent_id' => 'nullable|integer',
            'folder' => 'nullable|string|max:255',
            'folder_content_type' => 'nullable|string|max:128',
            'content_type' => 'nullable|string|max:128',
            'short_name' => 'nullable|string|max:32',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'language' => 'nullable|string|max:2',
            'files' => 'nullable|array',
            'files.*' => 'file|max:102400',
            'file' => 'nullable|file|max:102400',
        ]);

        $parent = $archive;
        if (!$parent) {
            $parent = Archive::resolveParent($request->integer('parent_id'), $request->input('language'));
        }

        if (!$parent->isFolder()) {
            return response()->json(['message' => 'Files can only be uploaded to folders.'], 422);
        }

        if ($request->filled('folder')) {
            $folder = Archive::query()
                ->where('parent_id', $parent->id)
                ->where('title', Archive::sanitizeTitle($request->string('folder')->toString()))
                ->where('type', Archive::TYPE_FOLDER)
                ->first();

            $parent = $folder ?: Archive::createFolder($parent, [
                'title' => $request->string('folder')->toString(),
                'content_type' => $request->input('folder_content_type', 'folder'),
                'language' => $request->input('language'),
            ]);
        }

        $files = $request->file('files', []);
        if ($request->hasFile('file')) {
            $files[] = $request->file('file');
        }

        if (count($files) === 0) {
            return response()->json(['message' => 'No files were provided.'], 422);
        }

        $created = [];
        foreach ($files as $file) {
            $created[] = Archive::createFile($parent, $file, $request->only([
                'content_type',
                'short_name',
                'title',
                'description',
                'language',
            ]))->toTreeData();
        }

        return response()->json([
            'parent' => $parent->toTreeData(),
            'files' => $created,
        ], 201);
    }

    public function update(Request $request, Archive $archive): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:102400',
        ]);

        $archive->replaceFile($request->file('file'));

        DB::table('archive_updates')->insert([
            'user_id' => $request->user()->id,
            'archive_id' => $archive->id,
            'main_archive_id' => $archive->related_id ?: $archive->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json($archive->fresh()->toTreeData());
    }

    public function destroy(Archive $archive): JsonResponse
    {
        $archive->deleteArchive();

        return response()->json(['message' => 'Archive deleted successfully.']);
    }

    public function download(Archive $archive)
    {
        return $archive->downloadResponse();
    }

    private function parentPath(Archive $archive): array
    {
        $items = [];

        if ($archive->isRoot()) {
            return [$archive->toTreeData()];
        }

        $current = $archive;
        while ($current) {
            array_unshift($items, $current->toTreeData());
            $current = $current->parent;
        }

        array_unshift($items, Archive::root()->toTreeData());

        return collect($items)
            ->unique('id')
            ->values()
            ->all();
    }
}