<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $connection_id
 * @property int $snapshot_id
 * @property int $user_id
 * @property int $official_user_id
 * @property string $status
 * @property bool $restricted_at_request
 * @property int|null $reviewed_by
 * @property \Carbon\Carbon|null $reviewed_at
 * @property \Carbon\Carbon|null $applied_at
 * @property string|null $decision_note
 * @property string|null $failure_reason
 * @property int|null $removed_by
 * @property \Carbon\Carbon|null $removed_at
 * @property string|null $remove_reason
 * @property bool $restricted_before_removal
 * @property int|null $removal_account_history_id
 * @property int|null $restored_by
 * @property \Carbon\Carbon|null $restored_at
 * @property string|null $restore_reason
 * @property int|null $restore_account_history_id
 * @property-read M1pposuOfficialConnection $connection
 * @property-read UserAccountHistory|null $removalAccountHistory
 * @property-read User|null $removedBy
 * @property-read UserAccountHistory|null $restoreAccountHistory
 * @property-read User|null $restoredBy
 * @property-read User|null $reviewer
 * @property-read M1pposuAccountImportSnapshot $snapshot
 * @property-read User $user
 */
class M1pposuAccountImportRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DENIED = 'denied';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REMOVED_BY_STAFF = 'removed_by_staff';
    public const STATUS_SELF_REMOVED = 'self_removed';

    public const ACTIVE_STATUS = self::STATUS_APPLIED;
    public const IMPORTED_STATUSES = [
        self::STATUS_APPLIED,
        self::STATUS_REMOVED_BY_STAFF,
        self::STATUS_SELF_REMOVED,
    ];
    public const REMOVED_STATUSES = [
        self::STATUS_REMOVED_BY_STAFF,
        self::STATUS_SELF_REMOVED,
    ];

    protected $casts = [
        'applied_at' => 'datetime',
        'removed_at' => 'datetime',
        'restored_at' => 'datetime',
        'restricted_before_removal' => 'boolean',
        'restricted_at_request' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(M1pposuOfficialConnection::class, 'connection_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    public function removalAccountHistory(): BelongsTo
    {
        return $this->belongsTo(UserAccountHistory::class, 'removal_account_history_id');
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }

    public function restoreAccountHistory(): BelongsTo
    {
        return $this->belongsTo(UserAccountHistory::class, 'restore_account_history_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(M1pposuAccountImportSnapshot::class, 'snapshot_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isRemoved(): bool
    {
        return in_array($this->status, self::REMOVED_STATUSES, true);
    }
}
