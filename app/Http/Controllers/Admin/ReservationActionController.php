<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendInvoiceEmailJob;
use App\Models\PostFiscalizationData;
use App\Models\Reservation;
use App\Services\FiscalizationService;
use App\Services\Payment\PostFiscalizationRecoveryDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin manual override: retry fiscalization, resend invoice, mark as resolved.
 */
class ReservationActionController extends Controller
{
    /**
     * Retry fiscalization za rezervaciju (ima post_fiscalization_data). Ista logika kao cron.
     */
    public function retryFiscalization(Request $request, int $id): RedirectResponse
    {
        $reservation = Reservation::find($id);
        if (! $reservation) {
            return redirect()->back()->with('error', 'Rezervacija nije pronađena.');
        }

        $post = PostFiscalizationData::where('reservation_id', $id)->unresolved()->first();
        if (! $post) {
            return redirect()->back()->with('error', 'Nema otvorenog zapisa za retry fiskalizacije (već fiskalizovano ili označeno kao rešeno).');
        }

        if ($reservation->fiscal_jir !== null) {
            $post->delete();

            return redirect()->back()->with('message', 'Rezervacija je već fiskalizovana.');
        }

        $result = app(FiscalizationService::class)->tryFiscalize($reservation);

        if (isset($result['fiscal_jir'])) {
            $post->applyFiscalDataAndDelete($result);
            SendInvoiceEmailJob::dispatch($reservation->id, true);
            app(PostFiscalizationRecoveryDispatcher::class)->signalFiscalChannelHealthy($reservation->id);

            return redirect()->back()->with('message', 'Fiskalizacija uspješna, fiskalni račun i email su poslati.');
        }

        $post->increment('attempts');
        $post->update([
            'error' => $result['error'] ?? 'Fiscal service unavailable',
            'next_retry_at' => now()->addMinutes(15 * $post->attempts),
        ]);

        $err = $result['error'] ?? 'nepoznata greška';

        return redirect()->back()->with('error', 'Fiskalizacija i dalje nije uspela: '.$err);
    }

    /**
     * Ponovo pošalji email sa računom: resetuje invoice_sent_at; PDF se generiše u jobu iz baze (on-demand).
     */
    public function resendInvoice(Request $request, int $id): RedirectResponse
    {
        $reservation = Reservation::find($id);
        if (! $reservation) {
            return redirect()->back()->with('error', 'Rezervacija nije pronađena.');
        }

        $reservation->update([
            'invoice_sent_at' => null,
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);
        $isFiscal = $reservation->fiscal_jir !== null;
        SendInvoiceEmailJob::dispatch($reservation->id, $isFiscal);

        return redirect()->back()->with('message', 'Račun (PDF + email) je ponovo u redu za slanje.');
    }

    /**
     * Mark post_fiscalization_data as resolved (cron više ne retry-uje).
     */
    public function markResolved(Request $request, int $id): RedirectResponse
    {
        $reservation = Reservation::find($id);
        if (! $reservation) {
            return redirect()->back()->with('error', 'Rezervacija nije pronađena.');
        }

        $post = PostFiscalizationData::where('reservation_id', $id)->unresolved()->first();
        if (! $post) {
            return redirect()->back()->with('error', 'Nema otvorenog zapisa za fiskalizaciju (već rešeno ili fiskalizovano).');
        }

        $post->update(['resolved_at' => now()]);

        return redirect()->back()->with('message', 'Označeno kao rešeno. Cron više neće retry-ovati fiskalizaciju za ovu rezervaciju.');
    }
}
