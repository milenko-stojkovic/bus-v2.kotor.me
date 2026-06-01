<?php

namespace App\Services\Pdf;

use App\Models\LimoPickupEvent;
use App\Models\Reservation;
use App\Services\Limo\LimoInvoiceAdapter;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use RuntimeException;

/**
 * Fiskalni ili nefiskalni račun (plaćena rezervacija) — PDF po V1 izgledu.
 * Sav vidljivi tekst u šablonu je na crnogorskom (cg); nema en varijante (zvanični izdavač).
 * Iznos isključivo iz {@see Reservation::$invoice_amount} (snapshot u bazi).
 */
class PaidInvoicePdfGenerator
{
    public const NON_FISCAL_NOTE_TIME_SLOTS = 'Račun je važeći kao potvrda o kupovini termina. Fiskalizovani račun biće dostavljen naknadno.';

    public const NON_FISCAL_NOTE_DAILY_TICKET = 'Račun je važeći kao potvrda o kupovini dnevne karte. Fiskalizovani račun biće dostavljen naknadno.';

    /** @deprecated Use {@see nonFiscalNoteFor()} */
    public const NON_FISCAL_NOTE = self::NON_FISCAL_NOTE_TIME_SLOTS;

    public static function nonFiscalNoteFor(Reservation $reservation): string
    {
        return $reservation->isDailyTicket()
            ? self::NON_FISCAL_NOTE_DAILY_TICKET
            : self::NON_FISCAL_NOTE_TIME_SLOTS;
    }

    /**
     * PDF kao binarni sadržaj (bez čuvanja na disku). Baca izuzetak ako generisanje ne uspe — nema tihog null fallback-a.
     */
    public function renderBinary(Reservation $reservation, bool $isFiscal): string
    {
        $previousLocale = app()->getLocale();
        app()->setLocale('cg');

        try {
            $reservation->loadMissing(['vehicleType.translations', 'dropOffTimeSlot', 'pickUpTimeSlot']);

            $vehicleLine = 'Naknada';
            if ($reservation->vehicleType) {
                $vehicleLine = $reservation->vehicleType->getTranslatedDescription('cg')
                    ?: $reservation->vehicleType->getTranslatedName('cg')
                    ?: 'Naknada';
            }

            $unitPrice = (float) $reservation->invoice_amount;

            $fiscalDateTime = $reservation->created_at
                ? Carbon::parse($reservation->created_at)
                : now();

            $qrDataUri = $isFiscal
                ? KotorPdfAssets::fiscalVerificationQrDataUri($reservation->fiscal_qr)
                : null;

            $internalNumber = KotorPdfAssets::parseInternalNumberFromFiscalQr($reservation->fiscal_qr);

            $validityDate = $reservation->reservation_date
                ? Carbon::parse($reservation->reservation_date)->format('d.m.Y')
                : '—';

            $pdf = Pdf::loadView('pdf.paid-invoice', [
                'reservation' => $reservation,
                'isFiscal' => $isFiscal,
                'isDailyTicket' => $reservation->isDailyTicket(),
                'validityDateDisplay' => $validityDate,
                'logoDataUri' => KotorPdfAssets::logoDataUri(),
                'qrDataUri' => $qrDataUri,
                'countryDisplay' => KotorPdfAssets::countryDisplayCg((string) $reservation->country),
                'vehicleLine' => $vehicleLine,
                'unitPrice' => $unitPrice,
                'fiscalDateTime' => $fiscalDateTime,
                'internalNumber' => $internalNumber,
                'nonFiscalNote' => self::nonFiscalNoteFor($reservation),
            ])->setPaper('a4', 'portrait');

            $out = $pdf->output();
            if (! is_string($out) || $out === '') {
                throw new RuntimeException('Paid invoice PDF output empty.');
            }

            return $out;
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    /**
     * Isti šablon kao plaćena rezervacija; blok „Detalji rezervacije” zamijenjen limo poljima kada je $isLimoService.
     */
    public function renderLimoBinary(LimoPickupEvent $event, bool $isFiscal): string
    {
        $previousLocale = app()->getLocale();
        app()->setLocale('cg');

        try {
            $vm = LimoInvoiceAdapter::fromPickupEvent($event);
            $vm->fiscal_jir = $event->fiscal_jir;
            $vm->fiscal_ikof = $event->fiscal_ikof;
            $vm->fiscal_qr = $event->fiscal_qr;

            $vehicleLine = $event->service_name_snapshot ?: 'Naknada';
            $unitPrice = (float) $event->amount_snapshot;

            $fiscalDateTime = $event->fiscal_date
                ? Carbon::parse($event->fiscal_date)
                : ($event->occurred_at ? Carbon::parse($event->occurred_at) : now());

            $occurredAtDisplay = $event->occurred_at ? Carbon::parse($event->occurred_at) : null;

            $qrDataUri = $isFiscal
                ? KotorPdfAssets::fiscalVerificationQrDataUri($event->fiscal_qr)
                : null;

            $internalNumber = KotorPdfAssets::parseInternalNumberFromFiscalQr($event->fiscal_qr);

            $pdf = Pdf::loadView('pdf.paid-invoice', [
                'reservation' => $vm,
                'isFiscal' => $isFiscal,
                'isLimoService' => true,
                'occurredAtDisplay' => $occurredAtDisplay,
                'logoDataUri' => KotorPdfAssets::logoDataUri(),
                'qrDataUri' => $qrDataUri,
                'countryDisplay' => KotorPdfAssets::countryDisplayCg((string) ($event->agency_country_snapshot ?? '')),
                'vehicleLine' => $vehicleLine,
                'unitPrice' => $unitPrice,
                'fiscalDateTime' => $fiscalDateTime,
                'internalNumber' => $internalNumber,
                'nonFiscalNote' => self::NON_FISCAL_NOTE,
            ])->setPaper('a4', 'portrait');

            $out = $pdf->output();
            if (! is_string($out) || $out === '') {
                throw new RuntimeException('Limo paid invoice PDF output empty.');
            }

            return $out;
        } finally {
            app()->setLocale($previousLocale);
        }
    }
}
