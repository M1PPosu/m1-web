<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

return [
    'support' => [
        'convinced' => [
            'title' => 'I\'m convinced! :D',
            'support' => 'support M1PPosu',
            'gift' => 'or gift supporter to other players',
            'instructions' => 'click the heart button to proceed to the M1PPosu Store',
        ],
        'why-support' => [
            'title' => 'Why should I support M1PPosu? Where does the money go?',

            'team' => [
                'title' => 'Support the Team',
                'description' => 'A small team develops and runs M1PPosu. Your support helps cover development and operations.',
            ],
            'infra' => [
                'title' => 'Server Infrastructure',
                'description' => 'Contributions go towards the servers for running the website, multiplayer services, online leaderboards, etc.',
            ],
            'featured-artists' => [
                'title' => 'Aura Factor',
                'description' => 'You will just be cool, with m1pp supporter you gain an intant x999999 aura',
                'link_text' => '',
            ],
            'ads' => [
                'title' => 'Keep M1PPosu self-sustaining',
                'description' => 'Your contributions help keep M1PPosu online.',
            ],
            'tournaments' => [
                'title' => 'Server Events',
                'description' => 'Help fund future server-run events and community prizes when available.',
                'link_text' => 'Explore tournaments &raquo;',
            ],
            'bounty-program' => [
                'title' => 'Open Source Bounty Program',
                'description' => 'Support the community contributors that have given their time and effort to help make osu! better.',
                'link_text' => 'Find out more &raquo;',
            ],
        ],
        'perks' => [
            'title' => 'Cool! What perks do I get?',
            'osu_direct' => [
                'title' => 'Beatmap Access',
                'description' => 'Supporter beatmap download benefits depend on the server launcher and mirror configuration.',
            ],

            'friend_ranking' => [
                'title' => 'Friend Ranking',
                'description' => "See how you stack up against your friends on a beatmap's leaderboard, both in-game and on the website.",
            ],

            'country_ranking' => [
                'title' => 'Country Ranking',
                'description' => 'Conquer your country before you conquer the world.',
            ],

            'mod_filtering' => [
                'title' => 'Filter by Mods',
                'description' => 'Associate only with people who play HDHR? No problem!',
            ],

            'auto_downloads' => [
                'title' => 'Automatic Downloads',
                'description' => 'Beatmaps will automatically download in multiplayer games, while spectating others, or when clicking relevant links in chat!',
            ],

            'upload_more' => [
                'title' => 'Upload More',
                'description' => 'Additional pending beatmap slots (per ranked beatmap) up to a max of 10.',
            ],

            'early_access' => [
                'title' => 'Early Access',
                'description' => 'Gain early access to new releases with new features before they go public!<br/><br/>This includes early access to new features on the website too!',
            ],

            'customisation' => [
                'title' => 'Customisation',
                'description' => "Stand out by uploading a custom cover image, creating a fully customizable 'me!' section, or even change the colour to any of your liking within your user profile.",
            ],

            'beatmap_filters' => [
                'title' => 'Beatmap Filters',
                'description' => 'Filter beatmap searches by played and unplayed maps, or by rank achieved.',
            ],

            'yellow_fellow' => [
                'title' => 'Yellow Fellow',
                'description' => 'Be recognised in-game with your new bright yellow chat username colour.',
            ],

            'speedy_downloads' => [
                'title' => 'Speedy Downloads',
                'description' => 'More lenient download restrictions when server download support is configured.',
            ],

            'change_username' => [
                'title' => 'Change Username',
                'description' => 'One free name change is included with your first supporter purchase.',
            ],

            'skinnables' => [
                'title' => 'Skinnables',
                'description' => 'Extra in-game skinnables, like the main menu background.',
            ],

            'feature_votes' => [
                'title' => 'Feature Votes',
                'description' => 'Votes for feature requests. (2 per month)',
            ],

            'sort_options' => [
                'title' => 'Sort Options',
                'description' => 'The ability to view beatmap country / friend / mod-specific rankings in-game.',
            ],

            'more_favourites' => [
                'title' => 'More Favourites',
                'description' => 'The maximum number of beatmaps you can favourite is increased from :normally &rarr; :supporter',
            ],
            'more_friends' => [
                'title' => 'More Friends',
                'description' => 'The maximum number of friends you can have is increased from :normally &rarr; :supporter',
            ],
            'more_beatmaps' => [
                'title' => 'Upload More Beatmaps',
                'description' => 'How many pending beatmaps you can have at once is calculated from a base value plus an additional bonus for each ranked beatmap you currently have (up to a limit).<br/><br/>Normally this is :base plus :bonus per ranked beatmap (up to :bonus_max). With supporter, this increases to :supporter_base plus :supporter_bonus per ranked beatmap (up to :supporter_bonus_max).',
            ],
            'friend_filtering' => [
                'title' => 'Friend Leaderboards',
                'description' => 'Compete with your friends and see how you rank up against them!',
            ],

        ],
        'supporter_status' => [
            'contribution_with_duration' => 'Thank you for your ongoing support! So far, you\'ve contributed a total of :dollars, earning you the "Supporter" tag for :duration.',
            'not_yet' => "You haven't ever had a M1PPosu supporter tag :(",
            'valid_until' => 'Your current M1PPosu supporter tag is valid until :date!',
            'was_valid_until' => 'Your M1PPosu supporter tag was valid until :date.',

            'gifted' => [
                '_' => 'Out of your total contributions, you’ve gifted :dollars worth of tags to :users covering :duration. That’s incredibly generous!',
                'users' => ':count_delimited other user|:count_delimited other users',
            ],
        ],
    ],
];
