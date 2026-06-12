{{--
    Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
    See the LICENCE file in the repository root for full licence text.
--}}
@extends('master')

@php
    $user = Auth::user();
    $isSupporter = $user?->isSupporter() ?? false;
    $url = $isSupporter
        ? osu_url('testflight.supporter')
        : osu_url('testflight.public');
@endphp
@section('content')
    @include('layout._page_header_v4', ['params' => [
        'theme' => 'home',
    ]])
    <div class="osu-page osu-page--generic">
        @if ($url === null)
            <p>iOS TestFlight downloads are not available from this private-server environment yet.</p>
        @else
            <p>A private-server iOS beta link is configured for this environment.</p>

            <center><a href="{{ $url }}" rel="nofollow noreferrer">{{ $url }}</a></center>
        @endif
    </div>
@endsection
