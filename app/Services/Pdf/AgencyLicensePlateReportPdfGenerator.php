<?php

namespace App\Services\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;
use RuntimeException;

class AgencyLicensePlateReportPdfGenerator
{
    /**
     * @param  array<string, mixed>  $dataset
     */
    public function renderBinary(array $dataset, string $locale = 'cg'): string
    {
        $previousLocale = app()->getLocale();
        $locale = $this->normalizeLocale($locale);
        app()->setLocale($locale);

        try {
            $pdf = Pdf::loadView('pdf.agency-license-plate-report', [
                'dataset' => $dataset,
                'logoDataUri' => KotorPdfAssets::logoDataUri(),
            ])->setPaper('a4', 'landscape');

            $out = $pdf->output();
            if (! is_string($out) || $out === '') {
                throw new RuntimeException('Agency license plate report PDF output empty.');
            }

            return $out;
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    private function normalizeLocale(string $locale): string
    {
        $l = strtolower(trim($locale));
        if ($l === 'en' || $l === 'cg') {
            return $l;
        }

        return 'cg';
    }
}
