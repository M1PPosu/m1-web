<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

return [
    'contact' => [
        'title' => 'contact us',
        'sections' => [
            'support' => [
                'title' => 'Support',
                'body' => [
                    'For account help, supporter or store questions, abuse reports, security reports, and general support, email :email.',
                    'Include enough context for staff to understand the issue. Do not send passwords, password hashes, API keys, database credentials, session cookies, or private keys.',
                ],
            ],
            'account-help' => [
                'title' => 'Account help',
                'items' => [
                    'Include your username or user id.',
                    'Explain whether the issue is about login, email, verification, authenticator setup, supporter status, or profile data.',
                    'If the issue involves source account data, include the source username used on the server.',
                ],
            ],
            'reports' => [
                'title' => 'Reports',
                'items' => [
                    'Use the same contact address for abuse, security, and account compromise reports.',
                    'Include links, screenshots, dates, and a short summary when reporting abuse or a site issue.',
                    'Do not expect instant replies. Staff will prioritise account safety, security issues, and severe community problems first.',
                ],
            ],
        ],
    ],

    'report_abuse' => [
        'title' => 'report abuse',
        'sections' => [
            'what-to-report' => [
                'title' => 'What to report',
                'items' => [
                    'harassment, threats, hate speech, targeted abuse, doxxing, or impersonation',
                    'cheating, ban evasion, account sharing abuse, boosting, or score manipulation',
                    'illegal content, malicious downloads, phishing, malware, or content that puts users at risk',
                    'security issues, account compromise, leaked private data, or serious exploit reports',
                    'spam, filter abuse, disruptive behaviour, or other community rule violations',
                ],
            ],
            'what-to-include' => [
                'title' => 'What to include',
                'items' => [
                    'the username or user id of each account involved',
                    'links to profiles, beatmaps, forum posts, scores, chat logs, or messages where possible',
                    'screenshots, dates, approximate times, and a short summary',
                    'your contact email if staff need to follow up',
                ],
            ],
            'security-reports' => [
                'title' => 'Security reports',
                'body' => [
                    'Report serious security issues privately to :email. Do not publish exploit steps, bypass details, or private account data in public chats, forums, or userpages.',
                ],
            ],
            'what-not-to-send' => [
                'title' => 'What not to send',
                'items' => [
                    'passwords',
                    'password hashes',
                    'API keys or OAuth secrets',
                    'database credentials',
                    'session cookies',
                    'private SSH keys or server access tokens',
                ],
            ],
            'how-to-send-reports' => [
                'title' => 'How to send reports',
                'body' => [
                    'Email reports to :email. There is no public report form on this site.',
                ],
            ],
        ],
    ],

    'rules' => [
        'title' => 'rules',
        'sections' => [
            'community-rules' => [
                'title' => 'Community rules',
                'body' => [
                    'These rules apply to the website, game server, chat, forums, profiles, clans, userpages, avatars, banners, and any other public M1PPosu community space.',
                ],
                'items' => [
                    'Be respectful to other players and staff.',
                    'Do not harass, threaten, use hate speech, target users for abuse, dox users, impersonate other people, or encourage self-harm.',
                    'Keep public spaces appropriate for a broad audience.',
                    'Do not evade moderation actions, restrictions, silences, or bans.',
                    'Do not advertise cheats, account selling, phishing pages, malware, malicious downloads, or private data leaks.',
                ],
                'ordered' => true,
            ],
            'account-rules' => [
                'title' => 'Account rules',
                'items' => [
                    'One account per player unless staff explicitly approves an exception.',
                    'Do not sell, share, trade, lend, or borrow accounts.',
                    'Do not use another player\'s account to farm, boost, bypass restrictions, dodge punishments, or impersonate them.',
                    'Keep account credentials private. Staff will never ask for your password.',
                    'If an account is compromised, contact staff privately as soon as possible.',
                ],
                'ordered' => true,
            ],
            'fair-play-and-anti-cheat-rules' => [
                'title' => 'Fair play and anti-cheat rules',
                'items' => [
                    'Do not use cheats, relax hacks, aim assist, timewarp, replay bots, score submitters, memory editors, client tampering, packet tampering, or automation that plays for you.',
                    'Macros are not allowed when one physical input does not equal one intended in-game action.',
                    'Do not manipulate the client, server, API, packets, or local files to submit impossible or illegitimate scores.',
                    'Do not exploit bugs to gain pp, rank, score, profile, clan, or statistic advantages.',
                    'Report serious exploits privately instead of posting public instructions.',
                ],
                'ordered' => true,
            ],
            'farming-server-and-leaderboard-integrity-rules' => [
                'title' => 'Farming server and leaderboard integrity rules',
                'body' => [
                    'M1PPosu may allow farm-style ranked maps and fast progression. That does not make cheating, boosting, or leaderboard manipulation acceptable.',
                ],
                'items' => [
                    'Farming a ranked map legitimately is allowed.',
                    'Boosting through cheats, multiaccounting, shared accounts, replay abuse, bug exploitation, or manipulated clients is not allowed.',
                    'Do not coordinate fake competitive activity to manipulate rankings, team rankings, clan rankings, or top plays.',
                    'Do not abuse restricted, unranked, loved, pending, or broken map states to appear in ranked-only surfaces.',
                    'Top plays and ranking surfaces must represent legitimate ranked plays only.',
                ],
                'ordered' => true,
            ],
            'in-game-chat-and-filter-abuse-rules' => [
                'title' => 'In-game chat and filter abuse rules',
                'items' => [
                    'Do not spam, flood, repeatedly disrupt channels, or abuse excessive caps.',
                    'Do not evade filters, bypass slur filters, post sexual or graphic content, or intentionally trigger moderation edge cases.',
                    'Do not harass staff or users over restrictions, silences, reports, or moderation decisions.',
                    'Do not advertise cheats, account selling, phishing, malicious downloads, private data leaks, or unrelated services.',
                    'Use staff channels or contact email for serious reports instead of public callouts.',
                ],
                'ordered' => true,
            ],
            'forum-and-user-generated-content-rules' => [
                'title' => 'Forum and user-generated content rules',
                'items' => [
                    'Keep posts meaningful and relevant to the topic.',
                    'Do not post NSFW content, gore, illegal content, pirated content, malware, phishing links, doxxing, or targeted harassment.',
                    'Do not claim to be staff if you are not staff.',
                    'Userpages, avatars, banners, clan names, clan tags, clan descriptions, and profile content must follow the same rules.',
                    'Staff may remove content that breaks rules or creates a safety risk.',
                ],
                'ordered' => true,
            ],
            'beatmap-and-ranking-rules' => [
                'title' => 'Beatmap and ranking rules',
                'items' => [
                    'M1PPosu map status is authoritative for rankings and profile score surfaces.',
                    'Ranked, loved, pending, unranked, and graveyard states must not be faked through web-only UI paths.',
                    'Do not upload stolen or plagiarised content where uploads or discussion are supported.',
                    'Do not exploit metadata, status, mode, variant, or score mismatches to gain ranking advantages.',
                    'Standard, Relax, and Autopilot ranking data must remain separate.',
                ],
                'ordered' => true,
            ],
            'staff-moderation-and-reports' => [
                'title' => 'Staff, moderation, and reports',
                'items' => [
                    'Staff decisions should be handled through official M1PPosu channels.',
                    'Do not publicly witch-hunt suspected cheaters; report with evidence instead.',
                    'Reports should include username or user id, score or map links, screenshots, dates, and a short summary.',
                    'Do not send passwords, password hashes, API keys, database credentials, session cookies, or private keys.',
                    'Moderation decisions may consider context, severity, history, and risk to the server.',
                ],
                'ordered' => true,
            ],
            'what-happens-if-i-break-the-rules' => [
                'title' => 'What happens if I break the rules?',
                'body' => [
                    'Penalties depend on severity, intent, history, and risk to the server. Staff may use one or more of the following actions.',
                ],
                'items' => [
                    'warnings',
                    'silences',
                    'score removal',
                    'restriction',
                    'removal from rankings',
                    'account lock or ban where appropriate',
                    'removal of userpage, avatar, banner, clan, or forum content',
                    'longer penalties for repeat or severe abuse',
                    'staff discretion for edge cases',
                ],
            ],
            'notes' => [
                'title' => 'Notes',
                'items' => [
                    'Rules may change as the server evolves.',
                    'If something is not listed but clearly harms the server or community, staff may still act.',
                    'Ask staff privately if you are unsure whether something is allowed.',
                    'M1PPosu is not affiliated with ppy Pty Ltd, osu!, or osu.ppy.sh.',
                ],
            ],
        ],
    ],
];
