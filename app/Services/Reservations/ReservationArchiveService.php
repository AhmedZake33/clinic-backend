<?php

namespace App\Services\Reservations;

use App\Models\Archive;
use App\Models\Reservation;
use Illuminate\Http\UploadedFile;

class ReservationArchiveService
{
    /**
     * Store completion files under archive/reservations/reservation-{id}.
     *
     * @param UploadedFile[] $files
     */
    public function storeCompletionFiles(Reservation $reservation, array $files): void
    {
        $folder = $this->ensureReservationFolder($reservation);

        foreach ($files as $file) {
            Archive::createFile($folder, $file, [
                'content_type' => 'reservation_completion_file',
                'language' => app()->getLocale(),
            ]);
        }
    }

    public function ensureReservationFolder(Reservation $reservation): Archive
    {
        if ($reservation->archive_id) {
            $archive = Archive::with('children')->find($reservation->archive_id);
            if ($archive) {
                return $archive;
            }
        }

        $rootFolder = Archive::query()
            ->where('parent_id', 0)
            ->where('type', Archive::TYPE_FOLDER)
            ->where('title', 'reservations')
            ->first();

        if (!$rootFolder) {
            $rootFolder = Archive::createFolder(Archive::root(), [
                'title' => 'reservations',
                'short_name' => 'reservations',
                'content_type' => 'reservations',
                'language' => app()->getLocale(),
            ]);
        }

        $folderTitle = 'reservation-' . $reservation->id;

        $reservationFolder = Archive::query()
            ->where('parent_id', $rootFolder->id)
            ->where('type', Archive::TYPE_FOLDER)
            ->where('title', $folderTitle)
            ->first();

        if (!$reservationFolder) {
            $reservationFolder = Archive::createFolder($rootFolder, [
                'title' => $folderTitle,
                'short_name' => $folderTitle,
                'content_type' => 'reservation_files',
                'language' => app()->getLocale(),
            ]);
        }

        if ($reservation->archive_id !== $reservationFolder->id) {
            $reservation->archive_id = $reservationFolder->id;
            $reservation->save();
        }

        return $reservationFolder->load('children');
    }
}
