<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->upSqlite();

            return;
        }

        $this->upMysql();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $this->downMysql();
    }

    private function upMysql(): void
    {
        $this->addReservationKindColumn('reservations');
        $this->makeSlotColumnsNullable('reservations', 'fk_res_drop', 'fk_res_pick');

        $this->addReservationKindColumn('temp_data');
        $this->makeSlotColumnsNullable('temp_data', 'fk_temp_drop', 'fk_temp_pick');
    }

    private function downMysql(): void
    {
        $this->makeSlotColumnsNotNullable('temp_data', 'fk_temp_drop', 'fk_temp_pick');
        $this->dropReservationKindColumn('temp_data');

        $this->makeSlotColumnsNotNullable('reservations', 'fk_res_drop', 'fk_res_pick');
        $this->dropReservationKindColumn('reservations');
    }

    private function addReservationKindColumn(string $table): void
    {
        if (Schema::hasColumn($table, 'reservation_kind')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->string('reservation_kind', 32)
                ->default('time_slots')
                ->after('merchant_transaction_id');
        });

        DB::table($table)->whereNull('reservation_kind')->update(['reservation_kind' => 'time_slots']);
    }

    private function dropReservationKindColumn(string $table): void
    {
        if (! Schema::hasColumn($table, 'reservation_kind')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropColumn('reservation_kind');
        });
    }

    private function makeSlotColumnsNullable(string $table, string $dropFk, string $pickFk): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($dropFk, $pickFk): void {
            $blueprint->dropForeign($dropFk);
            $blueprint->dropForeign($pickFk);
        });

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->unsignedInteger('drop_off_time_slot_id')->nullable()->change();
            $blueprint->unsignedInteger('pick_up_time_slot_id')->nullable()->change();
        });

        Schema::table($table, function (Blueprint $blueprint) use ($dropFk, $pickFk): void {
            $blueprint->foreign('drop_off_time_slot_id', $dropFk)
                ->references('id')->on('list_of_time_slots');
            $blueprint->foreign('pick_up_time_slot_id', $pickFk)
                ->references('id')->on('list_of_time_slots');
        });
    }

    private function makeSlotColumnsNotNullable(string $table, string $dropFk, string $pickFk): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($dropFk, $pickFk): void {
            $blueprint->dropForeign($dropFk);
            $blueprint->dropForeign($pickFk);
        });

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->unsignedInteger('drop_off_time_slot_id')->nullable(false)->change();
            $blueprint->unsignedInteger('pick_up_time_slot_id')->nullable(false)->change();
        });

        Schema::table($table, function (Blueprint $blueprint) use ($dropFk, $pickFk): void {
            $blueprint->foreign('drop_off_time_slot_id', $dropFk)
                ->references('id')->on('list_of_time_slots');
            $blueprint->foreign('pick_up_time_slot_id', $pickFk)
                ->references('id')->on('list_of_time_slots');
        });
    }

    private function upSqlite(): void
    {
        if (! Schema::hasTable('reservations')) {
            return;
        }

        DB::statement('PRAGMA foreign_keys=off');
        DB::statement('BEGIN TRANSACTION');

        $this->rebuildReservationsTableSqlite();
        $this->rebuildPostFiscalizationDataSqlite();
        $this->rebuildTempDataTableSqlite();

        DB::statement('COMMIT');
        DB::statement('PRAGMA foreign_keys=on');
    }

    private function rebuildReservationsTableSqlite(): void
    {
        DB::statement('ALTER TABLE reservations RENAME TO reservations_old');

        DB::statement(<<<'SQL'
CREATE TABLE reservations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NULL,
  vehicle_id INTEGER NULL,
  merchant_transaction_id VARCHAR(64) NULL,
  payment_method VARCHAR(32) NULL,
  reservation_kind VARCHAR(32) NOT NULL DEFAULT 'time_slots',
  drop_off_time_slot_id INTEGER NULL,
  pick_up_time_slot_id INTEGER NULL,
  reservation_date DATE NOT NULL,
  user_name VARCHAR(255) NOT NULL,
  country VARCHAR(100) NOT NULL,
  license_plate VARCHAR(50) NOT NULL,
  vehicle_type_id INTEGER NOT NULL,
  email VARCHAR(255) NOT NULL,
  preferred_locale VARCHAR(5) NULL,
  fiscal_jir VARCHAR(64) NULL,
  fiscal_ikof VARCHAR(64) NULL,
  fiscal_qr VARCHAR(255) NULL,
  fiscal_operator VARCHAR(64) NULL,
  fiscal_date DATETIME NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'paid',
  created_by_admin INTEGER NOT NULL DEFAULT 0,
  invoice_amount DECIMAL(10,2) NULL,
  invoice_sent_at TIMESTAMP NULL,
  email_sent INTEGER NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (drop_off_time_slot_id) REFERENCES list_of_time_slots(id),
  FOREIGN KEY (pick_up_time_slot_id) REFERENCES list_of_time_slots(id),
  FOREIGN KEY (vehicle_type_id) REFERENCES vehicle_types(id)
);
SQL);

        $hasPaymentMethod = Schema::hasColumn('reservations_old', 'payment_method');
        $hasInvoiceAmount = Schema::hasColumn('reservations_old', 'invoice_amount');
        $hasInvoiceSentAt = Schema::hasColumn('reservations_old', 'invoice_sent_at');
        $hasCreatedByAdmin = Schema::hasColumn('reservations_old', 'created_by_admin');
        $hasPreferredLocale = Schema::hasColumn('reservations_old', 'preferred_locale');

        $paymentMethodExpr = $hasPaymentMethod ? 'payment_method' : 'NULL';
        $invoiceAmountExpr = $hasInvoiceAmount ? 'invoice_amount' : 'NULL';
        $invoiceSentAtExpr = $hasInvoiceSentAt ? 'invoice_sent_at' : 'NULL';
        $createdByAdminExpr = $hasCreatedByAdmin ? 'created_by_admin' : '0';
        $preferredLocaleExpr = $hasPreferredLocale ? 'preferred_locale' : 'NULL';

        DB::statement(<<<SQL
INSERT INTO reservations (
  id, user_id, vehicle_id, merchant_transaction_id, payment_method, reservation_kind,
  drop_off_time_slot_id, pick_up_time_slot_id, reservation_date,
  user_name, country, license_plate, vehicle_type_id, email, preferred_locale,
  fiscal_jir, fiscal_ikof, fiscal_qr, fiscal_operator, fiscal_date,
  status, created_by_admin, invoice_amount, invoice_sent_at, email_sent,
  created_at, updated_at
)
SELECT
  id, user_id, vehicle_id, merchant_transaction_id, {$paymentMethodExpr}, 'time_slots',
  drop_off_time_slot_id, pick_up_time_slot_id, reservation_date,
  user_name, country, license_plate, vehicle_type_id, email, {$preferredLocaleExpr},
  fiscal_jir, fiscal_ikof, fiscal_qr, fiscal_operator, fiscal_date,
  status, {$createdByAdminExpr}, {$invoiceAmountExpr}, {$invoiceSentAtExpr}, email_sent,
  created_at, updated_at
FROM reservations_old
SQL);

        DB::statement('DROP TABLE reservations_old');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_res_merchant_tx ON reservations (merchant_transaction_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_res_date ON reservations (reservation_date)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_res_status ON reservations (status)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_res_vehicle ON reservations (vehicle_type_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_res_plate_date ON reservations (license_plate, reservation_date)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_res_user ON reservations (user_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_res_reservation_kind ON reservations (reservation_kind)');
    }

    /**
     * SQLite keeps FK targets on renamed tables; re-point post_fiscalization_data to new reservations.
     */
    private function rebuildPostFiscalizationDataSqlite(): void
    {
        if (! Schema::hasTable('post_fiscalization_data')) {
            return;
        }

        DB::statement('ALTER TABLE post_fiscalization_data RENAME TO post_fiscalization_data_old');

        DB::statement(<<<'SQL'
CREATE TABLE post_fiscalization_data (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  reservation_id INTEGER NOT NULL,
  merchant_transaction_id VARCHAR(64) NOT NULL,
  error TEXT NULL,
  attempts INTEGER NOT NULL DEFAULT 1,
  next_retry_at TIMESTAMP NULL,
  resolved_at TIMESTAMP NULL,
  admin_notified_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (reservation_id) REFERENCES reservations(id)
);
SQL);

        $hasError = Schema::hasColumn('post_fiscalization_data_old', 'error');
        $hasAttempts = Schema::hasColumn('post_fiscalization_data_old', 'attempts');
        $hasNextRetry = Schema::hasColumn('post_fiscalization_data_old', 'next_retry_at');
        $hasResolved = Schema::hasColumn('post_fiscalization_data_old', 'resolved_at');
        $hasAdminNotified = Schema::hasColumn('post_fiscalization_data_old', 'admin_notified_at');

        $errorExpr = $hasError ? 'error' : 'NULL';
        $attemptsExpr = $hasAttempts ? 'attempts' : '1';
        $nextRetryExpr = $hasNextRetry ? 'next_retry_at' : 'NULL';
        $resolvedExpr = $hasResolved ? 'resolved_at' : 'NULL';
        $adminNotifiedExpr = $hasAdminNotified ? 'admin_notified_at' : 'NULL';

        DB::statement(<<<SQL
INSERT INTO post_fiscalization_data (
  id, reservation_id, merchant_transaction_id, error, attempts, next_retry_at,
  resolved_at, admin_notified_at, created_at, updated_at
)
SELECT
  id, reservation_id, merchant_transaction_id, {$errorExpr}, {$attemptsExpr}, {$nextRetryExpr},
  {$resolvedExpr}, {$adminNotifiedExpr}, created_at, updated_at
FROM post_fiscalization_data_old
SQL);

        DB::statement('DROP TABLE post_fiscalization_data_old');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_post_fiscal_tx ON post_fiscalization_data (merchant_transaction_id)');
    }

    private function rebuildTempDataTableSqlite(): void
    {
        if (! Schema::hasTable('temp_data')) {
            return;
        }

        DB::statement('ALTER TABLE temp_data RENAME TO temp_data_old');

        DB::statement(<<<'SQL'
CREATE TABLE temp_data (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  merchant_transaction_id VARCHAR(64) NOT NULL,
  retry_token VARCHAR(36) NULL,
  user_id INTEGER NULL,
  vehicle_id INTEGER NULL,
  reservation_kind VARCHAR(32) NOT NULL DEFAULT 'time_slots',
  drop_off_time_slot_id INTEGER NULL,
  pick_up_time_slot_id INTEGER NULL,
  reservation_date DATE NOT NULL,
  user_name VARCHAR(255) NOT NULL,
  country VARCHAR(100) NOT NULL,
  license_plate VARCHAR(50) NOT NULL,
  vehicle_type_id INTEGER NOT NULL,
  invoice_amount_snapshot DECIMAL(10,2) NULL,
  email VARCHAR(255) NOT NULL,
  preferred_locale VARCHAR(5) NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'pending',
  raw_callback_payload TEXT NULL,
  callback_error_code VARCHAR(64) NULL,
  callback_error_reason TEXT NULL,
  resolution_reason VARCHAR(64) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (drop_off_time_slot_id) REFERENCES list_of_time_slots(id),
  FOREIGN KEY (pick_up_time_slot_id) REFERENCES list_of_time_slots(id),
  FOREIGN KEY (vehicle_type_id) REFERENCES vehicle_types(id)
);
SQL);

        $hasInvoiceSnapshot = Schema::hasColumn('temp_data_old', 'invoice_amount_snapshot');

        $invoiceSnapshotExpr = $hasInvoiceSnapshot ? 'invoice_amount_snapshot' : 'NULL';

        DB::statement(<<<SQL
INSERT INTO temp_data (
  id, merchant_transaction_id, retry_token, user_id, vehicle_id, reservation_kind,
  drop_off_time_slot_id, pick_up_time_slot_id, reservation_date,
  user_name, country, license_plate, vehicle_type_id, invoice_amount_snapshot, email, preferred_locale,
  status, raw_callback_payload, callback_error_code, callback_error_reason, resolution_reason,
  created_at, updated_at
)
SELECT
  id, merchant_transaction_id, retry_token, user_id, vehicle_id, 'time_slots',
  drop_off_time_slot_id, pick_up_time_slot_id, reservation_date,
  user_name, country, license_plate, vehicle_type_id, {$invoiceSnapshotExpr}, email, preferred_locale,
  status, raw_callback_payload, callback_error_code, callback_error_reason, resolution_reason,
  created_at, updated_at
FROM temp_data_old
SQL);

        DB::statement('DROP TABLE temp_data_old');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_temp_merchant_tx ON temp_data (merchant_transaction_id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_temp_retry_token ON temp_data (retry_token)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_temp_date ON temp_data (reservation_date)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_temp_status ON temp_data (status)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_temp_vehicle ON temp_data (vehicle_type_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_temp_merchant_tx ON temp_data (merchant_transaction_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_temp_plate_date ON temp_data (license_plate, reservation_date)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_temp_reservation_kind ON temp_data (reservation_kind)');
    }
};
