<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

return [
    'beatmapsets' => [
        'covers' => [
            'regenerate' => 'Regenerate',
            'regenerating' => 'Regenerating...',
            'remove' => 'Remove',
            'removing' => 'Removing...',
            'title' => 'Beatmap covers',
        ],
        'show' => [
            'covers' => 'Manage Beatmap Covers',
        ],
    ],

    'forum' => [
        'forum-covers' => [
            'index' => [
                'delete' => 'Delete',

                'forum-name' => 'Forum #:id: :name',

                'no-cover' => 'No cover set',

                'submit' => [
                    'save' => 'Save',
                    'update' => 'Update',
                ],

                'title' => 'Forum Covers List',

                'type-title' => [
                    'default-topic' => 'Default Topic Cover',
                    'main' => 'Forum Cover',
                ],
            ],
        ],
    ],

    'logs' => [
        'index' => [
            'title' => 'Log Viewer',
        ],
    ],

    'imported_accounts' => [
        'index' => [
            'action' => 'Action',
            'empty' => 'No imported accounts.',
            'flagged' => 'flagged',
            'imported_at' => 'Imported',
            'official_user' => 'Official osu! user',
            'remove' => 'Remove',
            'restore' => 'Restore',
            'restricted' => 'Restricted',
            'status' => 'Status',
            'title' => 'Imported Accounts',
            'user' => 'M1PPosu user',
        ],

        'show' => [
            'audit_history' => 'Audit history',
            'best_plays' => 'Best plays fetched',
            'captured_at' => 'Captured at',
            'connection_id' => 'Connection ID',
            'favourites' => 'Favorites fetched',
            'flagged' => 'flagged',
            'import_status' => 'Import status',
            'imported_at' => 'Imported',
            'imported_data_summary' => 'Imported data summary',
            'local_account' => 'Local account',
            'local_status' => 'Local status',
            'missing_data' => 'Missing data',
            'most_played' => 'Most-played fetched',
            'no_actions' => 'No staff action available.',
            'official_account' => 'Official account',
            'official_user' => 'Official osu! user',
            'remove' => 'Remove import',
            'remove_confirm' => 'I understand this hides imported official data and restricts the local user.',
            'remove_reason' => 'Required staff reason',
            'remove_reason_label' => 'Remove reason',
            'removed_at' => 'Removed',
            'request_id' => 'Request ID',
            'restore' => 'Restore import',
            'restore_confirm' => 'I understand this re-enables the imported data.',
            'restore_reason' => 'Required restore reason',
            'restore_reason_label' => 'Restore reason',
            'restored_at' => 'Restored',
            'restricted' => 'restricted',
            'restricted_flag' => 'Restricted official flag',
            'score_summaries' => 'Score summaries',
            'snapshot_id' => 'Snapshot ID',
            'snapshot_user' => 'Snapshot official user',
            'staff_actions' => 'Staff actions',
            'status' => 'Status',
            'technical_details' => 'Technical details',
            'title' => 'Imported Account',
            'unrestricted' => 'unrestricted',
            'user' => 'M1PPosu user',
        ],
    ],

    'official_import_requests' => [
        'index' => [
            'id' => 'ID',
            'failed' => 'Failed',
            'official_user' => 'Official osu! user',
            'requested' => 'Requested',
            'review' => 'Review',
            'review_reason' => 'Restricted/review reason',
            'reviewed' => 'Reviewed',
            'reviewer' => 'Reviewer',
            'restricted' => 'Restricted',
            'status' => 'Status',
            'title' => 'Official osu! Import Requests',
            'user' => 'M1PPosu user',
        ],

        'show' => [
            'accuracy' => 'Accuracy',
            'approve' => 'Approve',
            'captured_at' => 'Captured at',
            'connection_id' => 'Connection ID',
            'coverage' => 'Coverage',
            'decision_note' => 'Decision note',
            'deny' => 'Deny',
            'failure_reason' => 'Failure reason',
            'favorites_fetched' => 'Favorites fetched',
            'favourites_count' => ':count favourite beatmapsets',
            'fetch_failed' => 'fetch failed',
            'import_counts' => 'Imported snapshot counts',
            'import_scope' => 'Import scope',
            'kind' => 'Kind',
            'local_account' => 'Local account',
            'local_user' => 'M1PPosu user',
            'mode' => 'Mode',
            'most_played_fetched' => 'Most-played fetched',
            'most_played_count' => ':count most played beatmapsets',
            'native_changes' => 'Native site changes',
            'native_changes_none' => 'Native pp/rank data unchanged',
            'native_scores_safe' => 'Native score tables unchanged',
            'note_placeholder' => 'Optional note for audit trail and user email',
            'official_account' => 'Official account',
            'official_user' => 'Official osu! user',
            'play_count' => 'Play count',
            'request_id' => 'Request ID',
            'request_summary' => 'Request summary',
            'requested' => 'Requested',
            'requested_scope' => 'Requested import scope',
            'restricted' => 'Restricted',
            'review_decision' => 'Review decision',
            'reviewed' => 'Reviewed',
            'reviewed_by' => 'Reviewed by',
            'reviewer' => 'Reviewer',
            'score_count' => 'Scores fetched',
            'score_samples' => 'Official score summary samples',
            'score_summaries_count' => ':count score summaries',
            'scores_fetched' => 'Scores fetched',
            'snapshot_id' => 'Snapshot ID',
            'snapshot_summary' => 'Snapshot summary',
            'snapshot_user' => 'Snapshot official user',
            'staff_notes' => 'Staff notes',
            'statistics' => 'Snapshot statistics',
            'status' => 'Status',
            'technical_details' => 'Technical details',
            'title' => 'Official osu! Import Request',
            'total_score' => 'Total score',
            'unavailable_data' => 'Unavailable data',
            'unavailable_history' => 'Limited endpoint history',
            'unavailable_scores' => 'Complete ranked score history',
            'username_conflict' => 'Username differs from the local account.',
            'username_conflict_label' => 'Username conflict',
        ],
    ],

    'pages' => [
        'root' => [
            'sections' => [
                'beatmapsets' => 'Beatmaps',
                'forum' => 'Forum',
                'general' => 'General',

                'users' => [
                    'header' => 'User',
                    'cover_presets' => 'Profile Cover Presets',
                ],
            ],
        ],
    ],

    'users' => [
        'restricted_banner' => [
            'title' => 'This user is currently restricted.',
            'message' => '(only admins can see this)',
        ],
    ],

];
