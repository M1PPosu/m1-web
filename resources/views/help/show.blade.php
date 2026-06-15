{{--
    Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
    See the LICENCE file in the repository root for full licence text.
--}}
@extends('master', ['titlePrepend' => $title])

@section('content')
    @include('layout._page_header_v4', ['params' => [
        'links' => $links,
        'theme' => 'help',
    ]])

    <div class="osu-page osu-page--wiki">
        <div class="wiki-page">
            <div class="wiki-page__toc">
                <button
                    class="sidebar__mobile-toggle js-mobile-toggle"
                    data-mobile-toggle-target="help-toc"
                    type="button"
                >
                    {{ osu_trans('wiki.show.toc') }}
                </button>

                <div
                    class="sidebar__content hidden-xs js-mobile-toggle"
                    data-mobile-toggle-id="help-toc"
                >
                    <ol class="wiki-toc-list wiki-toc-list--top">
                        @foreach ($sections as $sectionKey => $section)
                            @php
                                $sectionId = is_string($sectionKey) ? $sectionKey : \Illuminate\Support\Str::slug($section['title']);
                            @endphp
                            <li class="wiki-toc-list__item">
                                <a class="wiki-toc-list__link" href="#{{ $sectionId }}">
                                    {{ $section['title'] }}
                                </a>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>

            <div class="wiki-page__content">
                <div class="osu-md osu-md--wiki">
                    <h1 class="osu-md__header osu-md__header--1">{{ $title }}</h1>

                    @if ($page !== 'rules')
                        <h2 class="osu-md__header osu-md__header--2">
                            {{ osu_trans('help.support_channels.title') }}
                        </h2>
                        <p class="osu-md__paragraph">
                            {{ osu_trans('help.support_channels.email') }}
                            <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>
                        </p>

                        @if ($page === 'contact')
                            <p class="osu-md__paragraph">
                                {{ osu_trans('help.support_channels.community') }}
                                <a href="{{ $discordUrl }}" rel="noopener noreferrer" target="_blank">
                                    {{ osu_trans('help.support_channels.discord') }}
                                </a>
                            </p>
                        @endif

                        @if ($helpForumUrl !== null)
                            <p class="osu-md__paragraph">
                                <a href="{{ $helpForumUrl }}">{{ osu_trans('help.support_channels.forum') }}</a>
                            </p>
                        @endif
                    @endif

                    @foreach ($sections as $sectionKey => $section)
                        @php
                            $sectionId = is_string($sectionKey) ? $sectionKey : \Illuminate\Support\Str::slug($section['title']);
                            $body = $section['body'] ?? [];
                            $body = is_array($body) ? $body : [$body];
                            $ordered = $section['ordered'] ?? false;
                        @endphp

                        <h2 id="{{ $sectionId }}" class="osu-md__header osu-md__header--2">{{ $section['title'] }}</h2>

                        @foreach ($body as $paragraph)
                            <p class="osu-md__paragraph">{{ $paragraph }}</p>
                        @endforeach

                        @if (isset($section['items']))
                            @if ($ordered)
                                <ol class="osu-md__list osu-md__list--ordered" style="--list-start: 0;">
                            @else
                                <ul class="osu-md__list">
                            @endif

                                @foreach ($section['items'] as $item)
                                    <li class="osu-md__list-item">
                                        @if ($ordered)
                                            <div>{{ $item }}</div>
                                        @else
                                            {{ $item }}
                                        @endif
                                    </li>
                                @endforeach

                            @if ($ordered)
                                </ol>
                            @else
                                </ul>
                            @endif
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endsection
