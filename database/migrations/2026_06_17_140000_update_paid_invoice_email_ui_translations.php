<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['group' => 'emails', 'key' => 'paid_invoice_email_subject', 'locale' => 'en', 'text' => 'Confirmation of reservation payment - Municipality of Kotor'],
            ['group' => 'emails', 'key' => 'paid_invoice_email_subject', 'locale' => 'cg', 'text' => 'Potvrda plaćanja rezervacije - Opština Kotor'],
            ['group' => 'emails', 'key' => 'paid_invoice_email_body', 'locale' => 'en', 'text' => "Dear, %1\$s\n\nYour reservation has been successfully confirmed!\n\nAttached to this email you will find your Invoice for the payment.\n\nPlease keep it for your records.\n\nBest regards,\nMunicipality of Kotor\nThis message was generated automatically %2\$s"],
            ['group' => 'emails', 'key' => 'paid_invoice_email_body', 'locale' => 'cg', 'text' => "Poštovani, %1\$s\n\nVaša rezervacija je uspješno potvrđena!\n\nUz ovu poruku u prilogu se nalazi Vaš račun za plaćanje.\n\nMolimo Vas da ga sačuvate radi evidencije.\n\nS poštovanjem,\nOpština Kotor\nOva poruka je automatski generisana %2\$s"],
            ['group' => 'emails', 'key' => 'paid_invoice_email_body_daily_ticket', 'locale' => 'en', 'text' => "Dear, %1\$s\n\nYour reservation has been successfully confirmed!\n\nAttached to this email you will find your Invoice for the payment.\n\nPlease keep it for your records.\n\nBest regards,\nMunicipality of Kotor\nThis message was generated automatically %2\$s"],
            ['group' => 'emails', 'key' => 'paid_invoice_email_body_daily_ticket', 'locale' => 'cg', 'text' => "Poštovani, %1\$s\n\nVaša rezervacija je uspješno potvrđena!\n\nUz ovu poruku u prilogu se nalazi Vaš račun za plaćanje.\n\nMolimo Vas da ga sačuvate radi evidencije.\n\nS poštovanjem,\nOpština Kotor\nOva poruka je automatski generisana %2\$s"],
        ];

        $now = now();
        foreach ($rows as $row) {
            DB::table('ui_translations')->updateOrInsert(
                ['group' => $row['group'], 'key' => $row['key'], 'locale' => $row['locale']],
                ['text' => $row['text'], 'updated_at' => $now, 'created_at' => $now],
            );
        }

        Cache::forget('ui_translations:group=emails:locale=cg');
        Cache::forget('ui_translations:group=emails:locale=en');
        foreach ([
            'paid_invoice_email_subject',
            'paid_invoice_email_body',
            'paid_invoice_email_body_daily_ticket',
        ] as $key) {
            Cache::forget('ui_translations:any:group=emails:key='.$key);
        }
    }

    public function down(): void
    {
        // Previous copy intentionally not restored.
    }
};
