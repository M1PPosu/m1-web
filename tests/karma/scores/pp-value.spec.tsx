// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

import * as React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import PpValue from 'scores/pp-value';

describe('PpValue', () => {
  it('marks imported official score pp with the requested tooltip', () => {
    const html = renderToStaticMarkup(
      <PpValue score={{ pp: 321.45, type: 'm1pposu_official_import' } as any} />,
    );

    expect(html).toContain('pp-value__imported-marker');
    expect(html).toContain('title="Imported Bancho Score"');
    expect(html).toContain('fa-star');
  });

  it('does not mark native score pp as imported', () => {
    const html = renderToStaticMarkup(
      <PpValue score={{ best_id: 1, pp: 321.45, type: 'solo_score' } as any} />,
    );

    expect(html).not.toContain('pp-value__imported-marker');
    expect(html).not.toContain('Imported Bancho Score');
  });
});
