<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalFileArchive extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_FAILED = 'failed';

    /** Historical failed attempt whose logical source later has an uploaded sibling. */
    public const STATUS_SUPERSEDED = 'superseded';

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
        'archived_derivative',
        'derivative_source_path',
        'derivative_options',
        'local_deleted_at',
        'preview_restored_at',
        'preview_expires_at',
        'archived_at',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'archived_derivative' => 'boolean',
            'derivative_options' => 'array',
            'archived_at' => 'datetime',
            'local_deleted_at' => 'datetime',
            'preview_restored_at' => 'datetime',
            'preview_expires_at' => 'datetime',
        ];
    }
}
