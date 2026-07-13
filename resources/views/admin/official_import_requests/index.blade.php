{{--
    Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
    See the LICENCE file in the repository root for full licence text.
--}}
@extends('master', ['titlePrepend' => osu_trans('admin.official_import_requests.index.title')])

@section('content')
    @include('admin._header')
    <div class="osu-page osu-page--admin">
        <div class="admin-official-import-requests">
            <h2 class="title">{{ osu_trans('admin.official_import_requests.index.title') }}</h2>

            <div class="admin-official-import-requests__list">
                <div class="admin-official-import-requests__list-header">
                    <span>{{ osu_trans('admin.official_import_requests.index.id') }}</span>
                    <span>{{ osu_trans('admin.official_import_requests.index.user') }}</span>
                    <span>{{ osu_trans('admin.official_import_requests.index.official_user') }}</span>
                    <span>{{ osu_trans('admin.official_import_requests.index.status') }}</span>
                    <span>{{ osu_trans('admin.official_import_requests.index.review_reason') }}</span>
                    <span>{{ osu_trans('admin.official_import_requests.index.requested') }}</span>
                    <span>{{ osu_trans('admin.official_import_requests.index.reviewed') }}</span>
                    <span>{{ osu_trans('admin.official_import_requests.index.reviewer') }}</span>
                    <span></span>
                </div>

                @foreach ($requests as $request)
                    <a class="admin-official-import-requests__list-row" href="{{ route('admin.official-import-requests.show', $request) }}">
                        <span>#{{ $request->getKey() }}</span>

                        <span class="admin-official-import-requests__user">
                            <strong>{{ $request->user?->username ?? $request->user_id }}</strong>
                            <span>#{{ $request->user_id }}</span>
                        </span>

                        <span class="admin-official-import-requests__user">
                            <strong>{{ $request->connection?->username ?? $request->official_user_id }}</strong>
                            <span>#{{ $request->official_user_id }}</span>
                        </span>

                        <span>
                            <span class="admin-official-import-requests__status admin-official-import-requests__status--{{ $request->status }}">
                                {{ $request->status }}
                            </span>
                            @if ($request->restricted_at_request)
                                <span class="admin-official-import-requests__flag">
                                    {{ osu_trans('admin.official_import_requests.index.restricted') }}
                                </span>
                            @endif
                        </span>

                        <span>
                            @if ($request->restricted_at_request)
                                {{ osu_trans('admin.official_import_requests.index.restricted') }}
                            @elseif (present($request->failure_reason))
                                {{ osu_trans('admin.official_import_requests.index.failed') }}
                            @elseif (present($request->decision_note))
                                {{ $request->decision_note }}
                            @else
                                <span class="admin-official-import-requests__muted">-</span>
                            @endif
                        </span>

                        <span>{!! timeago($request->created_at) !!}</span>

                        <span>
                            @if ($request->reviewed_at !== null)
                                {!! timeago($request->reviewed_at) !!}
                            @else
                                <span class="admin-official-import-requests__muted">-</span>
                            @endif
                        </span>

                        <span>
                            @if ($request->reviewer !== null)
                                {{ $request->reviewer->username }}
                            @else
                                <span class="admin-official-import-requests__muted">-</span>
                            @endif
                        </span>

                        <span class="admin-official-import-requests__action">
                            {{ osu_trans('admin.official_import_requests.index.review') }}
                        </span>
                    </a>
                @endforeach
            </div>

            {{ $requests->links() }}
        </div>
    </div>
@endsection
