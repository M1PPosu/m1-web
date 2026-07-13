<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

return [
    'edit' => [
        'title_compact' => 'account settings',
        'username' => 'username',

        'avatar' => [
            'title' => 'Avatar',
            'reset' => 'reset',
            'rules' => 'Please ensure your avatar adheres to :link.<br/>This means it must be <strong>suitable for all ages</strong>. i.e. no nudity, offensive or suggestive content.',
            'rules_link' => 'the Visual content considerations',
        ],

        'email' => [
            'new' => 'new email',
            'new_confirmation' => 'email confirmation',
            'title' => 'Email',
            'locked' => [
                '_' => 'Please contact the :accounts if you need your email updated.',
                'accounts' => 'account support team',
            ],
        ],

        'legacy_api' => [
            'api' => 'api',
            'irc' => 'irc',
            'title' => 'Legacy API',
        ],

        'password' => [
            'current' => 'current password',
            'new' => 'new password',
            'new_confirmation' => 'password confirmation',
            'title' => 'Password',
        ],

        'profile' => [
            'country' => 'country',
            'title' => 'Profile',

            'country_change' => [
                '_' => "It looks like your account country doesn't match your country of residence. :update_link.",
                'update_link' => 'Update to :country',
            ],

            'user' => [
                'user_discord' => 'discord',
                'user_from' => 'current location',
                'user_interests' => 'interests',
                'user_occ' => 'occupation',
                'user_twitter' => 'twitter',
                'user_website' => 'website',
            ],
        ],

        'signature' => [
            'title' => 'Signature',
            'update' => 'update',
        ],
    ],

    'github_user' => [
        'info' => "If you're a contributor to osu!'s open-source repositories, linking your GitHub account here will associate your changelog entries with your osu! profile. GitHub accounts with no contribution history to osu! cannot be linked.",
        'link' => 'Link GitHub Account',
        'title' => 'GitHub',
        'unlink' => 'Unlink GitHub Account',

        'error' => [
            'already_linked' => 'This GitHub account is already linked to a different user.',
            'no_contribution' => 'Cannot link GitHub account without any contribution history in osu! repositories.',
            'unverified_email' => 'Please verify your primary email on GitHub, then try linking your account again.',
        ],
    ],

    'official_osu' => [
        'connected' => 'Your official osu! account has been connected.',
        'connected_heading' => 'Official osu! account connected',
        'import' => 'Import Official Data',
        'import_applied' => 'Official osu! data imported. Continue your profile on M1PPosu with native pp, ranks, and leaderboards earned here.',
        'import_prompt' => 'Import supported official osu! profile/history data into your M1PPosu profile. Imported official scores do not grant M1PPosu pp or leaderboard placement.',
        'import_started' => 'Official osu! data imported.',
        'info' => 'Connect your official osu! account to verify ownership and import supported profile/history data into your M1PPosu profile.',
        'link' => 'Connect official osu! account',
        'official_user_id' => 'official user id',
        'restricted' => 'This official osu! account is flagged for staff review after import.',
        'review_denied' => 'Your official osu! account import request was reviewed and denied.',
        'review_failed' => 'Your official osu! account import request was reviewed, but could not be applied. Please contact support if this continues.',
        'review_requested' => 'Your official osu! account appears to be restricted, so your import request has been sent to our Trust & Safety team for manual review. You will receive a decision by email once the review is complete. Most requests are handled quickly, but reviews may take up to 24 hours.',
        'reimport_started' => 'Official osu! data refreshed and reimported.',
        'title' => 'Connected Accounts',
        'unlink' => 'Disconnect official osu! account',
        'unavailable' => 'Official osu! account linking is not configured for this server.',

        'confirm' => [
            'checkbox' => 'I understand and want to import my official osu! data.',
              'locked' => 'After importing, removal requires a separate confirmation and blocks normal manual reimport.',
            'native_intact' => 'Your existing M1PPosu scores stay intact.',
            'no_pp' => 'Imported official scores do not grant M1PPosu pp or leaderboard placement.',
            'profile' => 'This imports supported official osu! profile/history data into your M1PPosu profile.',
            'title' => 'Before importing',
        ],

        'admin' => [
            'reimport' => 'Reimport official data',
            'reset' => 'Reset official import link',
        ],

        'preview' => [
            'native_unchanged' => 'M1PPosu pp, ranks, leaderboards, teams, and supporter perks remain M1PPosu-earned only.',
            'profile' => 'Continue your profile on M1PPosu with supported official osu! identity and history.',
            'reconnect_required' => 'Reconnect this official osu! account before refreshing or importing more supported data.',
            'username_conflict' => 'Official username differs from your M1PPosu username and cannot be applied automatically.',
        ],

        'remove' => [
            'button' => 'Remove official import',
            'checkbox' => 'I understand and want to remove my official osu! import.',
            'native_intact' => 'Your native M1PPosu account and scores remain.',
            'profile' => 'Imported official data will be removed from your public profile.',
            'reimport_blocked' => 'You will not be able to manually import official data again afterward.',
            'removed_by_staff' => 'This official osu! import was removed by staff.',
            'self_removed' => 'You removed this official osu! import. Staff can help if this was a mistake.',
            'staff_help' => 'Staff may need to assist if this was a mistake.',
            'title' => 'Remove official import',
        ],

        'state' => [
            'denied' => 'denied',
            'failed' => 'failed',
              'imported' => 'imported',
              'not_connected' => 'No official osu! account connected',
              'pending' => 'pending review',
              'ready' => 'ready to import',
              'removed_by_staff' => 'removed by staff',
              'review_required' => 'review required',
              'self_removed' => 'self removed',
          ],

        'error' => [
              'confirm_required' => 'Please confirm that official data is imported as non-competitive history before importing.',
              'import_already_applied' => 'Official osu! data has already been imported for this account.',
              'import_self_removed' => 'This official osu! import was removed and cannot be manually imported again. Please contact staff if this was a mistake.',
              'local_already_linked' => 'Your M1PPosu account is already connected to a different official osu! account.',
              'official_already_linked' => 'This official osu! account is already connected to a different M1PPosu account.',
              'remove_confirm_required' => 'Please confirm that you understand what removing the official osu! import does.',
              'reconnect_required' => 'Please reconnect your official osu! account before importing.',
              'unlink_locked' => 'This official osu! connection cannot be removed after importing or while staff review is pending.',
          ],
          'remove_removed' => 'Official osu! import removed from your public profile.',
      ],

    'notifications' => [
        'beatmapset_discussion_qualified_problem' => 'receive notifications for new problems on qualified beatmaps of the following modes',
        'beatmapset_disqualify' => 'receive notifications for when beatmaps of the following modes are disqualified',
        'comment_reply' => 'receive notifications for replies to your comments',
        'news_post' => 'receive notifications for news posts',
        'title' => 'Notifications',
        'topic_auto_subscribe' => 'automatically enable notifications on new forum topics that you create or replied to',

        'options' => [
            '_' => 'delivery options',
            'beatmap_owner_change' => 'guest difficulty',
            'beatmapset:modding' => 'beatmap modding',
            'channel_message' => 'private chat messages',
            'channel_mention' => 'chat mention',
            'channel_team' => 'team chat messages',
            'comment_new' => 'new comments',
            'forum_topic_reply' => 'topic reply',
            'mail' => 'mail',
            'mapping' => 'beatmap mapper',
            'news_post' => 'news posts',
            'push' => 'push',
        ],
    ],

    'oauth' => [
        'authorized_clients' => 'authorized clients',
        'own_clients' => 'own clients',
        'title' => 'OAuth',
    ],

    'options' => [
        'beatmapset_show_anime_cover' => 'show anime style beatmap covers',
        'beatmapset_show_nsfw' => 'hide warnings for explicit content in beatmaps',
        'beatmapset_title_show_original' => 'show beatmap metadata in original language',
        'title' => 'Options',

        'beatmapset_download' => [
            '_' => 'default beatmap download type',
            'all' => 'with video if available',
            'direct' => 'open in direct',
            'no_video' => 'without video',
        ],
    ],

    'playstyles' => [
        'keyboard' => 'keyboard',
        'mouse' => 'mouse',
        'tablet' => 'tablet',
        'title' => 'Playstyles',
        'touch' => 'touch',
    ],

    'privacy' => [
        'friends_only' => 'block private messages from people not on your friends list',
        'hide_online' => 'hide your online presence',
        'hide_online_info' => 'this maps to the "appear offline" mode in osu!lazer',
        'title' => 'Privacy',
    ],

    'security' => [
        'current_session' => 'current',
        'end_session' => 'End Session',
        'end_session_confirmation' => 'This will immediately end your session on that device. Are you sure?',
        'last_active' => 'Last active:',
        'title' => 'Security',
        'web_sessions' => 'web sessions',
    ],

    'update_email' => [
        'update' => 'update',
    ],

    'update_password' => [
        'update' => 'update',
    ],

    'user_totp' => [
        'title' => 'Authenticator App',
        'usage_note' => 'Use authenticator app instead of email for verification. Email verification will still be available as a fallback.',

        'button' => [
            'remove' => 'Remove',
            'setup' => 'Add Authenticator App',
        ],
        'status' => [
            'label' => 'status',
            'not_set' => 'Not configured',
            'set' => 'Configured',
        ],
    ],

    'verification_completed' => [
        'text' => 'You can close this tab/window now',
        'title' => 'Verification has been completed',
    ],

    'verification_invalid' => [
        'title' => 'Invalid or expired verification link',
    ],
];
