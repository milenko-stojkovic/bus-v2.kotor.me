<?php

namespace Tests\Unit\Models;

use App\Models\Reservation;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationInvoiceFilenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_pdf_filename_uses_reservation_id_and_date(): void
    {
        $vehicleType = VehicleType::query()->create(['price' => 15]);

        $reservation = Reservation::query()->create([
            'reservation_date' => '2026-06-10',
            'user_name' => 'Test',
            'country' => 'ME',
            'license_plate' => 'PG TEST',
            'vehicle_type_id' => $vehicleType->id,
            'email' => 'test@example.test',
            'status' => 'paid',
        ]);

        $this->assertSame(
            'invoice-'.$reservation->id.'-2026-06-10.pdf',
            $reservation->invoicePdfFilename()
        );
    }

    public function test_free_confirmation_pdf_filename_uses_reservation_id_and_date(): void
    {
        $vehicleType = VehicleType::query()->create(['price' => 0]);

        $reservation = Reservation::query()->create([
            'reservation_date' => '2026-06-13',
            'user_name' => 'Test',
            'country' => 'ME',
            'license_plate' => 'PG FREE',
            'vehicle_type_id' => $vehicleType->id,
            'email' => 'free@example.test',
            'status' => 'free',
        ]);

        $this->assertSame(
            'free-confirmation-'.$reservation->id.'-2026-06-13.pdf',
            $reservation->freeConfirmationPdfFilename()
        );
    }
}
