<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalFileArchive extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_FAILED = 'failed';

    public const PROVIDER_MEGA = 'mega';

    protected $fillable = [
        'source_table',
        'source_id',
        'source_column',
        'context_type',
        'archive_provider',
        'generated_file_name',
        'mega_node_id',
        'mega_path',
        'original_local_path',
        'local_deleted_at',
        'archived_at',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'local_deleted_at' => 'datetime',
        ];
    }
}
