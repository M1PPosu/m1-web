{{--
    Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
    See the LICENCE file in the repository root for full licence text.
--}}
@extends('master', ['titlePrepend' => osu_trans('admin.imported_accounts.index.title')])

@section('content')
    @include('admin._header')
    <div class="osu-page osu-page--admin">
        <div class="admin-imported-accounts">
            <h2 class="title">{{ osu_trans('admin.imported_accounts.index.title') }}</h2>

            <div class="admin-imported-accounts__list">
                <div class="admin-imported-accounts__list-header">
                    <span>{{ osu_trans('admin.imported_accounts.index.user') }}</span>
                    <span>{{ osu_trans('admin.imported_accounts.index.official_user') }}</span>
                    <span>{{ osu_trans('admin.imported_accounts.index.imported_at') }}</span>
                    <span>{{ osu_trans('admin.imported_accounts.index.restricted') }}</span>
                    <span>{{ osu_trans('admin.imported_accounts.index.status') }}</span>
                    <span>{{ osu_trans('admin.imported_accounts.index.action') }}</span>
                </div>

                @forelse ($imports as $import)
                    <div class="admin-imported-accounts__list-row">
                        <span class="admin-imported-accounts__user">
                            <strong>
                                @if ($import->user !== null)
                                    <a href="{{ $import->user->url() }}">{{ $import->user->username }}</a>
                                @else
                                    #{{ $import->user_id }}
                                @endif
                            </strong>
                            <span>#{{ $import->user_id }}</span>
                        </span>

                        <span class="admin-imported-accounts__user">
                            <strong>
                                @if ($import->connection !== null)
                                    <a href="{{ $import->connection->officialUrl() }}">{{ $import->connection->username }}</a>
                                @else
                                    #{{ $import->official_user_id }}
                                @endif
                            </strong>
                            <span>#{{ $import->official_user_id }}</span>
                        </span>

                        <span>{!! $import->applied_at === null ? '-' : timeago($import->applied_at) !!}</span>

                        <span>
                            @if ($import->restricted_at_request)
                                <span class="admin-imported-accounts__flag">{{ osu_trans('admin.imported_accounts.index.flagged') }}</span>
                            @else
                                <span class="admin-imported-accounts__muted">-</span>
                            @endif
                        </span>

                        <span>
                            <span class="admin-imported-accounts__status admin-imported-accounts__status--{{ $import->status }}">
                                {{ $import->status }}
                            </span>
                        </span>

                        <span class="admin-imported-accounts__action-cell">
                            <a
                                class="admin-imported-accounts__action-button admin-imported-accounts__action-button--{{ $import->isRemoved() ? 'restore' : 'remove' }}"
                                href="{{ route('admin.imported-accounts.show', $import) }}"
                            >
                                {{ osu_trans('admin.imported_accounts.index.'.($import->isRemoved() ? 'restore' : 'remove')) }}
                            </a>
                        </span>
                    </div>
                @empty
                    <div class="admin-imported-accounts__empty">
                        {{ osu_trans('admin.imported_accounts.index.empty') }}
                    </div>
                @endforelse
            </div>

            {{ $imports->links() }}
        </div>
    </div>
@endsection
