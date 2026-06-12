<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

return [
    'contact' => 'Contact',
    'header' => 'Legal',
    'last_updated' => 'Last updated: May 27, 2026',
    'non_affiliation' => 'M1PPosu is not affiliated with ppy Pty Ltd, osu!, or osu.ppy.sh.',
    'source' => 'Source code',

    'pages' => [
        'copyright' => [
            'title' => 'Copyright',
            'description' => 'Copyright and takedown information for M1PPosu.',
            'sections' => [
                [
                    'id' => 'project-code',
                    'title' => 'Project code and licence',
                    'body' => [
                        'This website is an AGPL-3.0 fork/adaptation of osu-web. Upstream osu-web copyright and licence notices are preserved in the repository.',
                        'M1PPosu-specific modifications are distributed under the same AGPL-3.0 licence unless a file states otherwise.',
                    ],
                ],
                [
                    'id' => 'third-party-rights',
                    'title' => 'Third-party rights',
                    'body' => [
                        'osu!, osu-web, ppy, and osu.ppy.sh are owned by their respective rights holders. Their names may appear for compatibility, attribution, or factual reference only.',
                        'Third-party libraries, fonts, icons, and assets remain under their own licences.',
                    ],
                ],
                [
                    'id' => 'reporting',
                    'title' => 'Reporting copyright issues',
                    'body' => [
                        'If content available through M1PPosu infringes your copyright or trademark rights, send a report to the contact address shown on this page.',
                        'Include enough detail for staff to identify the content and review the request:',
                    ],
                    'list' => [
                        'your name and contact email;',
                        'the copyrighted or trademarked work being reported;',
                        'the URL, beatmap, userpage, avatar, banner, forum post, clan, or other content being reported;',
                        'a short explanation of why the content should be removed or disabled;',
                        'a statement that the report is accurate and that you are authorised to act for the rights holder.',
                    ],
                    'after' => [
                        'Do not send passwords, password hashes, API keys, database credentials, private keys, or session cookies with a report.',
                    ],
                ],
                [
                    'id' => 'review',
                    'title' => 'What happens after a report',
                    'body' => [
                        'After receiving a complete report, staff may:',
                    ],
                    'list' => [
                        'remove or disable access to the reported content;',
                        'contact the user who uploaded or displayed the content;',
                        'request more information from the reporter or affected user;',
                        'restrict repeat or severe infringers where appropriate.',
                    ],
                ],
                [
                    'id' => 'user-content',
                    'title' => 'User content and media',
                    'body' => [
                        'Players are responsible for content they upload or publish, including userpages, avatars, banners, beatmap metadata, clan names, and forum posts.',
                        'Beatmap, skin, audio, image, and replay-related content may include work owned by third parties. Availability on this server does not grant ownership or extra rights.',
                    ],
                ],
                [
                    'id' => 'counter-notices',
                    'title' => 'Counter-notices',
                    'body' => [
                        'If your content was removed and you believe this was a mistake, contact staff with the content URL, your account name, and a short explanation.',
                        'M1PPosu may restore, keep disabled, or further review content depending on the available information and applicable risk.',
                    ],
                ],
            ],
        ],

        'privacy' => [
            'title' => 'Privacy Policy',
            'description' => 'This page explains what data M1PPosu uses to run the website and connected services.',
            'sections' => [
                [
                    'id' => 'account-data',
                    'title' => 'Account data',
                    'body' => [
                        'The website stores account records needed for sessions, profiles, forums, settings, notifications, and projected gameplay data.',
                        'When source login is enabled, account data is projected from the configured Shiina/bancho.py-ex source database. Source password hashes are not copied into the osu-web application database.',
                    ],
                ],
                [
                    'id' => 'gameplay-data',
                    'title' => 'Gameplay and profile data',
                    'body' => [
                        'Scores, statistics, ranks, beatmaps, clans, userpages, avatars, and profile covers may be projected from the source database or source file storage when available.',
                        'Public profile, ranking, beatmap, forum, and team pages may display gameplay and profile information for unrestricted users.',
                    ],
                ],
                [
                    'id' => 'security-logs',
                    'title' => 'Security and logs',
                    'body' => [
                        'The service may keep web server, application, authentication, moderation, and security logs to operate the site and investigate abuse.',
                        'Logs should not contain passwords, password hashes, database passwords, API keys, or session secrets.',
                    ],
                ],
                [
                    'id' => 'cookies',
                    'title' => 'Cookies and sessions',
                    'body' => [
                        'Cookies are used for login sessions, CSRF protection, preferences, and normal Laravel/osu-web functionality.',
                        'Production deployments should use HTTPS and secure session cookies.',
                    ],
                ],
                [
                    'id' => 'email',
                    'title' => 'Email and support',
                    'body' => [
                        'Email addresses may be used for login, account recovery, verification, notifications, moderation, and support contact.',
                        'Messages sent to support may be retained while a request, report, or security issue is handled.',
                    ],
                ],
                [
                    'id' => 'infrastructure',
                    'title' => 'Infrastructure providers',
                    'body' => [
                        'Production deployments may use reverse proxies, CDNs, mail providers, object storage, monitoring, backups, and hosting providers.',
                        'Those providers may process technical data such as IP addresses, request metadata, delivery logs, and security events as part of operating the service.',
                    ],
                ],
                [
                    'id' => 'contact',
                    'title' => 'Privacy contact',
                    'body' => [
                        'Use the contact address shown on this page for privacy or account data questions.',
                    ],
                ],
            ],
        ],

        'terms' => [
            'title' => 'Terms of Service',
            'description' => 'These terms describe the basic rules for using M1PPosu.',
            'sections' => [
                [
                    'id' => 'use',
                    'title' => 'Using the service',
                    'body' => [
                        'You are responsible for how your account is used. Keep your login details private and do not share, sell, trade, or lend accounts.',
                        'Use of the service is also subject to the M1PPosu rules page.',
                    ],
                ],
                [
                    'id' => 'fair-play',
                    'title' => 'Fair play',
                    'body' => [
                        'Cheating, automation that plays for you, score submission abuse, client tampering, packet tampering, and leaderboard manipulation are not allowed.',
                        'M1PPosu may support fast progression or farm-style ranked content, but rankings and top plays must still come from legitimate gameplay.',
                    ],
                ],
                [
                    'id' => 'content',
                    'title' => 'User content',
                    'body' => [
                        'You are responsible for userpages, forum posts, avatars, banners, clan content, beatmap metadata, and other content you submit or display.',
                        'Staff may remove content that violates rules, creates legal risk, or harms the service or community.',
                    ],
                ],
                [
                    'id' => 'moderation',
                    'title' => 'Moderation',
                    'body' => [
                        'Staff may issue warnings, silences, score removals, ranking removals, restrictions, account locks, or bans where appropriate.',
                        'Moderation decisions may consider context, severity, repeat behaviour, and risk to the service.',
                    ],
                ],
                [
                    'id' => 'availability',
                    'title' => 'Availability',
                    'body' => [
                        'M1PPosu is provided as a private-server service without a guarantee of uptime, data retention, compatibility, or permanent availability.',
                        'Features may change as M1PPosu, the launcher, and connected services are updated.',
                    ],
                ],
                [
                    'id' => 'supporter-store',
                    'title' => 'Supporter and store',
                    'body' => [
                        'Supporter or store features are only available when configured by the server. Do not assume purchases, entitlements, or payments are available unless the site clearly exposes a working flow.',
                    ],
                ],
                [
                    'id' => 'non-affiliation',
                    'title' => 'Non-affiliation',
                    'body' => [
                        'M1PPosu is not affiliated with ppy Pty Ltd, osu!, or osu.ppy.sh.',
                    ],
                ],
            ],
        ],
    ],
];
