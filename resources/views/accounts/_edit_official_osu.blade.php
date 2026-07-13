{{--
    Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
    See the LICENCE file in the repository root for full licence text.
--}}
<div class="account-edit" id="official-osu">
    <div class="account-edit__section">
        <h2 class="account-edit__section-title">
            {{ osu_trans('accounts.official_osu.title') }}
        </h2>
    </div>

    <div class="account-edit__input-groups">
        <div
            class="account-edit__input-group js-react"
            data-react="official-osu-connection"
            data-can-authenticate="{{ json_encode($officialOsuCanAuthenticate) }}"
            data-connection="{{ json_encode($officialOsuConnection) }}"
        ></div>
    </div>
</div>
