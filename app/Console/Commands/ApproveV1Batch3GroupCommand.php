<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Services\Reservation\HistoricalAttributionBatch3Approval;
use App\Services\Reservation\HistoricalAttributionBatch3Catalog;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Promote a frozen Batch 3 file to an approved CSV. Not scheduled.
 * Writes no database rows.
 */
class ApproveV1Batch3GroupCommand extends Command
{
    protected $signature = 'reservations:approve-v1-batch3-group
        {csv : Frozen eligible or hold CSV}
        {--manifest= : Frozen manifest.json}
        {--out= : New approved CSV outside this repository}
        {--confirm= : APPROVE-V1-BATCH-3 or APPROVE-V1-BATCH-3-REVIEW}
        {--admin-id= : admins.id with admin_access}
        {--reservation=* : Hold reservation ids. Required for review approval and refused for ordinary approval}';

    protected $description = 'Approve one frozen V1 batch 3 group. Writes nothing to the database.';

    public function handle(HistoricalAttributionBatch3Approval $approval): int
    {
        $csv = $this->argument('csv');
        $manifest = $this->option('manifest');
        $out = $this->option('out');
        $confirm = $this->option('confirm');
        if (! is_string($csv) || ! is_file($csv)) {
            $this->error('The candidate CSV was not found.');

            return self::FAILURE;
        }
        if (! is_string($manifest) || trim($manifest) === '' || ! is_string($out) || trim($out) === '' || ! is_string($confirm)) {
            $this->error('Pass --manifest, --out, and --confirm.');

            return self::FAILURE;
        }
        $actor = $this->actor();
        if ($actor === null) {
            $this->error('Refusing to approve. Pass --admin-id for an existing admin with admin_access.');

            return self::FAILURE;
        }

        try {
            $result = $approval->approve(trim($manifest), $csv, trim($out), $confirm, $actor, $this->reservationIds());
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Mode: approve');
        $this->line('Group: '.$result['group_id']);
        $this->line('Reservations: '.$result['reservations']);
        $this->line('Evidence: '.$result['evidence']);
        $this->line('Admin: '.$actor->id);
        $this->line('SHA-256: '.$result['sha256']);
        $this->line('Approved: '.$result['approved']);
        $this->line('No reservation or audit row was written.');

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function reservationIds(): array
    {
        $values = $this->option('reservation');
        if (! is_array($values)) {
            throw new RuntimeException('Pass each review reservation id with --reservation.');
        }
        $ids = [];
        foreach ($values as $value) {
            if (! is_string($value) && ! is_int($value)) {
                throw new RuntimeException('Pass each review reservation id as a positive integer.');
            }
            if (preg_match('/^[1-9][0-9]*$/', (string) $value) !== 1) {
                throw new RuntimeException('Pass each review reservation id as a positive integer.');
            }
            $ids[] = (int) $value;
        }

        return $ids;
    }

    private function actor(): ?Admin
    {
        $adminId = $this->option('admin-id');
        if ((! is_string($adminId) && ! is_int($adminId)) || preg_match('/^[1-9][0-9]*$/', (string) $adminId) !== 1) {
            return null;
        }

        return Admin::query()->whereKey((int) $adminId)->where('admin_access', true)->first();
    }
}
