{{--
    Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
    See the LICENCE file in the repository root for full licence text.
--}}
@extends('master', ['titlePrepend' => $page['title']])

@section('content')
    @component('layout._page_header_v4', ['params' => [
        'links' => [
            [
                'title' => osu_trans('layout.header.help.index'),
                'url' => wiki_url('Main_page', $locale),
            ],
            [
                'title' => $page['subtitle'],
            ],
            [
                'title' => $page['title'],
                'url' => wiki_url($path, $locale),
            ],
        ],
        'linksBreadcrumb' => true,
        'theme' => 'help',
    ]])
    @endcomponent

    <div class="osu-page osu-page--wiki">
        <div class="wiki-page">
            <div class="wiki-page__content">
                <div class="wiki-content">
                    <h1>{{ $page['title'] }}</h1>

                    @foreach ($page['sections'] as $section)
                        <h2 id="{{ $section['id'] }}">{{ $section['title'] }}</h2>

                        @foreach ($section['body'] as $paragraph)
                            <p>{{ $paragraph }}</p>
                        @endforeach
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endsection
