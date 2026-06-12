{{--
    Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
    See the LICENCE file in the repository root for full licence text.
--}}
@php
    use App\Libraries\User\CountryChangeTarget;
    use App\Models\Country;

    $countryChangeTarget = CountryChangeTarget::get($user);
    $countryCode = $user->country_acronym ?? Country::UNKNOWN;
    $countryName = $user->country?->name ?? app('countries')->byCode(Country::UNKNOWN)?->name ?? Country::UNKNOWN;
@endphp
<div class="account-edit-entry account-edit-entry--read-only">
    <div class="account-edit-entry__label account-edit-entry__label--top-pinned">
        {{ osu_trans('accounts.edit.profile.country') }}
    </div>
    <div class="account-edit-entry__group">
        <p>
            @include('objects._flag_country', [
                'country' => $countryCode,
                'modifiers' => 'wiki',
            ])
            {{ $countryName }}
        </p>
        @if ($countryChangeTarget !== null)
            <p>
                {!! osu_trans('accounts.edit.profile.country_change._', [
                    'update_link' => link_to(
                        route('account.country', ['country_acronym' => $countryChangeTarget]),
                        osu_trans('accounts.edit.profile.country_change.update_link', [
                            'country' => app('countries')->byCode($countryChangeTarget)?->name ?? $countryChangeTarget,
                        ]),
                        [
                            'data-confirm' => osu_trans('common.confirmation'),
                            'data-method' => 'PUT',
                            'data-remote' => '1',
                        ]
                    ),
                ]) !!}
            </p>
        @endif
    </div>
</div>
