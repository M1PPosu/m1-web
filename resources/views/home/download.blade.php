{{--
    Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
    See the LICENCE file in the repository root for full licence text.
--}}
@extends('master')

@section('content')
@component('layout._page_header_v4')
@endcomponent

<div class="osu-page osu-page--generic-compact">
    <div class="download-page">
        <div class="download-page__header">
            <div class="download-page__banner">
                <video
                    class="download-page__banner-video"
                    src="{{ $GLOBALS['cfg']['osu']['urls']['download_video'] }}"
                    autoplay
                    muted
                    loop
                    playsinline
                ></video>
                <div class="download-page__banner-content download-page__banner-content--main">
                    <div class="download-page__tagline">
                        <span class="download-page__tagline-1">{{ osu_trans('home.download.tagline_1') }}</span>
                        <span class="download-page__tagline-2">{{ osu_trans('home.download.tagline_2') }}</span>
                    </div>
                    <{{ $launcherUrl === null ? 'span' : 'a' }}
                        class="btn-osu-big btn-osu-big--download btn-osu-big--download-m1pposu {{ $launcherUrl === null ? 'btn-osu-big--disabled' : '' }}"
                        @if ($launcherUrl === null)
                            aria-disabled="true"
                            role="link"
                        @else
                            href="{{ $launcherUrl }}"
                        @endif
                    >
                        <div class="btn-osu-big__content">
                            <div class="btn-osu-big__left">
                                {{ osu_trans($launcherUrl === null ? 'home.download.download_disabled' : 'home.download.download') }}
                                <div>
                                    <div class="btn-osu-big__text-top btn-osu-big__text-top--download">
                                        {{ osu_trans('home.download.launcher') }}
                                    </div>
                                    {{ osu_trans('home.download.os.windows') }}
                                </div>
                                <div class="btn-osu-big__text-bottom btn-osu-big__text-bottom--download-version">
                                    {{ $version }}
                                </div>
                            </div>
                            <span class="btn-osu-big__icon">
                                <span class="svg-icon svg-icon--download"></span>
                            </span>
                        </div>
                    </{{ $launcherUrl === null ? 'span' : 'a' }}>
                </div>
            </div>
            <div class="download-page__banner-tail">
                <div class="download-page__banner-content download-page__banner-content--tail">
                    <div class="download-page__text download-page__text--download-stable">
                        <div>
                            {{ osu_trans('home.download.stable_text') }}:
                        </div>
                        @if ($launcherInfoUrl !== null)
                            <a href="{{ $launcherInfoUrl }}">
                                ({{ osu_trans('home.download.action_launcher_info') }})
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="download-page__guide">
            <div class="download-page__guide-content">
                <div class="download-page__steps">
                    <div class="download-page__step">
                        <span class="download-page__step-number">1</span>
                        <div class="download-page__text download-page__text--title">
                            {{ osu_trans("home.download.steps.download.title") }}
                        </div>
                        <div class="download-page__text download-page__text--description">
                            {{ osu_trans("home.download.steps.download.description") }}
                        </div>
                    </div>
                    <div class="download-page__step">
                        <span class="download-page__step-number">2</span>
                        <div class="download-page__text download-page__text--title">
                            {{ osu_trans('home.download.steps.register.title') }}
                        </div>
                        <div class="download-page__text download-page__text--description">
                            {{ osu_trans('home.download.steps.register.description') }}
                        </div>
                    </div>
                    <div class="download-page__step">
                        <span class="download-page__step-number">3</span>
                        <div class="download-page__text download-page__text--title">
                            {{ osu_trans("home.download.steps.beatmaps.title") }}
                        </div>
                        <div class="download-page__text download-page__text--description">
                            {{ osu_trans('home.download.steps.beatmaps.description') }}
                        </div>
                    </div>
                </div>

                @if ($GLOBALS['cfg']['services']['enchant']['id'] !== null)
                    <div class="download-page__help">
                        {!! osu_trans('home.download.help._', [
                            'support_button' => link_to(
                                '#',
                                osu_trans('home.download.help.support_button'),
                                [
                                    'class' => 'js-enchant--show',
                                    'role' => 'button',
                                ],
                            ),
                            'help_forum_link' => link_to(
                                route('forum.forums.show', ['forum' => $GLOBALS['cfg']['osu']['forum']['help_forum_id']]),
                                osu_trans('home.download.help.help_forum_link')
                            )
                        ]) !!}
                    </div>

                    @include('objects._enchant')
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
