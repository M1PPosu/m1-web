// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

import * as React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import Cover from 'profile-page/cover';

describe('ProfilePageCover', () => {
  it('anchors the official import marker to the username instead of the group badge flow', () => {
    const html = renderToStaticMarkup(
      <Cover
        coverUrl={null}
        currentMode='osu'
        user={{
          avatar_url: '/images/layout/avatar-guest.png',
          country: null,
          cover: { custom_url: null, id: '1', url: null },
          current_season_stats: null,
          groups: [
            {
              colour: '#ff66aa',
              has_listing: false,
              has_playmodes: false,
              id: 1,
              identifier: 'admin',
              is_probationary: false,
              name: 'Admin',
              playmodes: null,
              short_name: 'admin',
            },
            {
              colour: '#a84dff',
              has_listing: false,
              has_playmodes: false,
              id: 8,
              identifier: 'dev',
              is_probationary: false,
              name: 'Dev',
              playmodes: null,
              short_name: 'dev',
            },
          ],
          id: 10,
          is_supporter: false,
          official_import: {
            applied_at: '2026-07-13T00:00:00+00:00',
            official_url: 'https://osu.ppy.sh/users/33592661',
            official_user_id: 33592661,
            profile: {
              badges: [],
              badges_count: 0,
              country: null,
              is_supporter: false,
              join_date: null,
              title: null,
              username: 'Yomorei',
            },
            statistics: { current: null, modes: {} },
          },
          previous_usernames: [],
          support_level: 0,
          team: null,
          username: 'Yomorei',
        } as any}
      />,
    );

    const markerIndex = html.indexOf('profile-info__official-imported');
    const iconsIndex = html.indexOf('profile-info__icons profile-info__icons--name-inline');

    expect(markerIndex).toBeGreaterThan(html.indexOf('profile-info__name-text'));
    expect(markerIndex).toBeLessThan(iconsIndex);
    expect(html).toContain('title="Players who imported their official osu! accounts"');
    expect(html).toContain('data-label="admin"');
    expect(html).toContain('data-label="dev"');
    expect(html).toContain('/images/layout/avatar-guest.png');
    expect(html).not.toContain('user-group-badge--official-imported');
  });
});
