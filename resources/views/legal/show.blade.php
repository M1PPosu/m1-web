{{--
    Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
    See the LICENCE file in the repository root for full licence text.
--}}
@php
    $legalPage = osu_trans("legal.pages.{$pageKey}");
    $contactEmail = config('m1pposu.contact_email');
    $title = $legalPage['title'];
    $url = route('legal', ['locale' => $locale, 'path' => ucfirst($pageKey)]);
    $sourceCodeUrl = config('m1pposu.legal.source_code_url');
    $links = [
        [
            'title' => osu_trans('legal.header'),
            'url' => route('legal', ['locale' => $locale, 'path' => 'Terms']),
        ],
        compact('title', 'url'),
    ];
@endphp

@extends('master')

@section('content')
    @component('layout._page_header_v4', ['params' => [
        'links' => $links,
        'linksBreadcrumb' => true,
        'theme' => 'help',
    ]])
        @if ($sourceCodeUrl !== null)
            @slot('linksAppend')
                <div class="header-buttons">
                    <div class="header-buttons__item">
                        <a
                            class="btn-osu-big btn-osu-big--rounded-thin"
                            href="{{ $sourceCodeUrl }}"
                            title="{{ osu_trans('legal.source') }}"
                        >
                            <i class="fab fa-github"></i>
                        </a>
                    </div>
                </div>
            @endslot
        @endif
    @endcomponent

    <div class="osu-page osu-page--wiki">
        <div class="wiki-page">
            <div class="wiki-page__toc">
                <div class="sidebar">
                    <button
                        type="button"
                        class="sidebar__mobile-toggle js-mobile-toggle"
                        data-mobile-toggle-target="legal-toc"
                    >
                        <h2 class="sidebar__title">
                            {{ osu_trans('wiki.show.toc') }}
                        </h2>

                        <div class="visible-xs sidebar__mobile-toggle-icon">
                            <i class="fas fa-chevron-down"></i>
                        </div>
                    </button>

                    <div class="js-mobile-toggle hidden-xs sidebar__content" data-mobile-toggle-id="legal-toc">
                        <ul class="wiki-toc-list">
                            @foreach ($legalPage['sections'] as $section)
                                <li class="wiki-toc-list__item">
                                    <a class="wiki-toc-list__link" href="#{{ $section['id'] }}">
                                        {{ $section['title'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>

            <div class="wiki-page__content">
                <div class="osu-md osu-md--wiki">
                    <h1 class="osu-md__header osu-md__header--1">{{ $title }}</h1>
                    <p class="osu-md__paragraph">{{ $legalPage['description'] }}</p>
                    <p class="osu-md__paragraph">{{ osu_trans('legal.last_updated') }}</p>

                    @foreach ($legalPage['sections'] as $section)
                        <h2 id="{{ $section['id'] }}" class="osu-md__header osu-md__header--2">{{ $section['title'] }}</h2>

                        @foreach ($section['body'] as $paragraph)
                            <p class="osu-md__paragraph">{{ $paragraph }}</p>
                        @endforeach

                        @if (isset($section['list']))
                            @if ($section['ordered'] ?? true)
                                <ol class="osu-md__list osu-md__list--ordered">
                            @else
                                <ul class="osu-md__list">
                            @endif

                                @foreach ($section['list'] as $item)
                                    <li class="osu-md__list-item">{{ $item }}</li>
                                @endforeach

                            @if ($section['ordered'] ?? true)
                                </ol>
                            @else
                                </ul>
                            @endif
                        @endif

                        @foreach ($section['after'] ?? [] as $paragraph)
                            <p class="osu-md__paragraph">{{ $paragraph }}</p>
                        @endforeach
                    @endforeach

                    <h2 id="contact" class="osu-md__header osu-md__header--2">{{ osu_trans('legal.contact') }}</h2>
                    <p class="osu-md__paragraph">
                        <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>
                    </p>

                    <p class="osu-md__paragraph">
                        {{ config('m1pposu.legal.non_affiliation') ?? osu_trans('legal.non_affiliation') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
