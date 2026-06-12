<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Http\Controllers;

use App\Libraries\LocaleMeta;
use App\Models\Wiki;

class LegalController extends Controller
{
    private const LOCAL_PAGES = ['copyright', 'privacy', 'terms'];

    public function show($locale = null, $path = null)
    {
        if ($path === null && in_array(strtolower((string) $locale), static::LOCAL_PAGES, true)) {
            $path = $locale;
            $locale = app()->getLocale();
        }

        $locale ??= app()->getLocale();

        if (!LocaleMeta::isValid($locale)) {
            $redirect = concat_path(['Legal', $locale, $path]);

            return ujs_redirect(wiki_url($redirect));
        }

        if (substr(request()->getPathInfo(), -1) === '/') {
            return ujs_redirect(route('legal', ['path' => rtrim($path, '/'), 'locale' => $locale]));
        }

        $pageKey = strtolower((string) ($path ?? 'terms'));
        if (in_array($pageKey, static::LOCAL_PAGES, true)) {
            return ext_view('legal.show', compact('locale', 'pageKey'));
        }

        $page = Wiki\Page::lookupForController("Legal/{$path}", $locale);
        $legal = true;

        return ext_view('wiki.show', compact('legal', 'locale', 'page'));
    }

    public function update($locale, $path)
    {
        priv_check('WikiPageRefresh')->ensureCan();

        (new Wiki\Page("Legal/{$path}", $locale))->sync(true);

        return ext_view('layout.ujs-reload', [], 'js');
    }
}
