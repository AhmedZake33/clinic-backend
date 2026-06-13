<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class BackfillPositionsReservations extends Migration
{
    /**
     * Run the migrations.
     * Backfill `position` from `waiting_number` grouped by doctor and appointment date
     * for already checked-in reservations where `position` is null.
     *
     * @return void
     */
    public function up()
    {
        // Fetch relevant reservations ordered by doctor, date, waiting_number
        $rows = DB::table('reservations')
            ->whereNotNull('checked_in_at')
            ->whereNull('position')
            ->orderBy('doctor_id')
            ->orderBy('appointment_date')
            ->orderBy('waiting_number')
            ->get();

        // Group by doctor + appointment_date and assign incremental positions
        $groups = [];
        foreach ($rows as $r) {
            $key = $r->doctor_id . '|' . ($r->appointment_date ?? '');
            $groups[$key][] = $r;
        }

        foreach ($groups as $group) {
            $pos = 1;
            foreach ($group as $r) {
                DB::table('reservations')->where('id', $r->id)->update(['position' => $pos]);
                $pos++;
            }
        }
    }

    /**
     * Reverse the migrations.
     * (optional) clear positions that were backfilled
     *
     * @return void
     */
    public function down()
    {
        DB::table('reservations')->whereNotNull('position')->update(['position' => null]);
    }
}
