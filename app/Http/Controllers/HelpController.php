<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Http\Controllers;

class HelpController extends Controller
{
    private const PAGES = [
        'rules' => 'help.rules',
        'report_abuse' => 'help.report-abuse',
        'contact' => 'help.contact',
    ];

    public function contact()
    {
        return $this->show('contact');
    }

    public function reportAbuse()
    {
        return $this->show('report_abuse');
    }

    public function rules()
    {
        return $this->show('rules');
    }

    private function show(string $page)
    {
        return ext_view('help.show', [
            'contactEmail' => config('m1pposu.contact_email'),
            'links' => $this->links($page),
            'page' => $page,
            'sections' => osu_trans("help.{$page}.sections"),
            'title' => osu_trans("help.{$page}.title"),
        ]);
    }

    private function links(string $activePage): array
    {
        $links = [];

        foreach (self::PAGES as $page => $route) {
            $links[] = [
                'active' => $page === $activePage,
                'title' => osu_trans("help.{$page}.title"),
                'url' => route($route),
            ];
        }

        return $links;
    }
}
