<?php

namespace App\Services\Pdf;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Throwable;

/**
 * Zajednički asseti za Kotor PDF-ove (logo, QR za fiskalni URL, država cg).
 */
final class KotorPdfAssets
{
    /**
     * Grb Opštine Kotor za zvanične PDF-ove (račun, potvrde, izvještaji).
     */
    public static function logoDataUri(): ?string
    {
        return self::fileDataUri(public_path('images/logo_kotor.png'), 'image/png');
    }

    /**
     * @return non-empty-string|null
     */
    private static function fileDataUri(string $path, string $mime): ?string
    {
        if (! is_readable($path)) {
            return null;
        }
        $data = @file_get_contents($path);
        if ($data === false || $data === '') {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($data);
    }

    /**
     * QR slika (data URI) sa sadržajem fiskalnog verifikacionog URL-a.
     */
    public static function fiscalVerificationQrDataUri(?string $verificationUrl): ?string
    {
        if (! is_string($verificationUrl) || trim($verificationUrl) === '') {
            return null;
        }
        if (filter_var($verificationUrl, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        try {
            $qrCode = QrCode::create($verificationUrl)
                ->setEncoding(new Encoding('UTF-8'))
                ->setErrorCorrectionLevel(ErrorCorrectionLevel::Medium)
                ->setSize(160)
                ->setMargin(2)
                ->setRoundBlockSizeMode(RoundBlockSizeMode::Margin);

            $writer = new PngWriter;

            return $writer->write($qrCode)->getDataUri();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Interni broj iz fiscal_qr URL-a (V1: ord + godina iz crtd).
     */
    public static function parseInternalNumberFromFiscalQr(?string $fiscalQr): ?string
    {
        if (! is_string($fiscalQr) || $fiscalQr === '') {
            return null;
        }
        if (! preg_match('/ord=(\d+)/', $fiscalQr, $ordMatches)) {
            return null;
        }
        $ordNumber = $ordMatches[1];
        if (! preg_match('/crtd=(\d{4})-\d{2}-\d{2}/', $fiscalQr, $yearMatches)) {
            return null;
        }

        return $ordNumber.'/'.$yearMatches[1];
    }

    public static function countryDisplayCg(string $code): string
    {
        $cc = strtoupper(trim($code));
        if ($cc === '' || $cc === 'OTHER') {
            return $code;
        }
        $cfg = (array) config('countries', []);
        if (isset($cfg[$cc]) && is_array($cfg[$cc])) {
            return $cfg[$cc]['cg'] ?? ($cfg[$cc]['en'] ?? $code);
        }

        return $code;
    }
}
