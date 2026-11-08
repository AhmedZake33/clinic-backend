<?php

namespace Tests\Unit;

use App\Services\Reservations\ReservationBookingService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Pure booking rules (no database): overlap detection and status transitions.
 *
 *   php artisan test --filter=ReservationBookingRulesTest
 */
class ReservationBookingRulesTest extends TestCase
{
    public function test_a_booking_inside_the_same_slot_overlaps(): void
    {
        $booked = [Carbon::parse('2026-11-10 10:00')];

        $this->assertTrue(ReservationBookingService::overlapsAny($booked, Carbon::parse('2026-11-10 10:15'), 30));
        $this->assertTrue(ReservationBookingService::overlapsAny($booked, Carbon::parse('2026-11-10 10:00'), 30));
        $this->assertTrue(ReservationBookingService::overlapsAny($booked, Carbon::parse('2026-11-10 09:45'), 30));
    }

    public function test_adjacent_slots_do_not_overlap(): void
    {
        $booked = [Carbon::parse('2026-11-10 10:00')];

        $this->assertFalse(ReservationBookingService::overlapsAny($booked, Carbon::parse('2026-11-10 10:30'), 30));
        $this->assertFalse(ReservationBookingService::overlapsAny($booked, Carbon::parse('2026-11-10 09:30'), 30));
    }

    public function test_slot_length_decides_the_overlap(): void
    {
        $booked = [Carbon::parse('2026-11-10 10:00')];

        $this->assertFalse(ReservationBookingService::overlapsAny($booked, Carbon::parse('2026-11-10 10:15'), 15));
        $this->assertTrue(ReservationBookingService::overlapsAny($booked, Carbon::parse('2026-11-10 10:15'), 20));
    }

    public function test_no_bookings_means_no_overlap(): void
    {
        $this->assertFalse(ReservationBookingService::overlapsAny([], Carbon::parse('2026-11-10 10:00'), 30));
    }

    public function test_status_transitions(): void
    {
        $service = new ReservationBookingService();

        $this->assertNull($service->transitionError('pending', 'confirmed'));
        $this->assertNull($service->transitionError('pending', 'no_show'));
        $this->assertNull($service->transitionError('confirmed', 'completed'));
        $this->assertNull($service->transitionError('confirmed', 'no_show'));
        $this->assertNull($service->transitionError('completed', 'completed')); // unchanged status is fine

        $this->assertNotNull($service->transitionError('pending', 'completed'));
        $this->assertNotNull($service->transitionError('completed', 'pending'));
        $this->assertNotNull($service->transitionError('cancelled', 'confirmed'));
        $this->assertNotNull($service->transitionError('no_show', 'confirmed'));
        $this->assertNotNull($service->transitionError('no_show', 'completed'));
    }
}
