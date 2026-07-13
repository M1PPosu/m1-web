// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

import ScoreJson from 'interfaces/score-json';
import * as React from 'react';
import { formatNumber } from 'utils/html';
import { trans } from 'utils/lang';

interface Props {
  score: ScoreJson;
  suffix?: React.ReactNode;
}

function pp(score: ScoreJson, suffix: React.ReactNode, imported: boolean) {
  return (
    <span className='pp-value'>
      <span title={formatNumber(score.pp ?? 0)}>
        {formatNumber(Math.round(score.pp ?? 0))}
        {suffix}
      </span>
      {imported && (
        <span
          className='pp-value__imported-marker'
          data-tooltip-position='top center'
          title='Imported Bancho Score'
        >
          <span className='fas fa-star' />
        </span>
      )}
    </span>
  );
}

export default function PpValue({ score, suffix }: Props) {
  if (score.type === 'm1pposu_official_import') {
    if (score.pp == null) {
      return <span title={trans('scores.status.no_pp')}>-</span>;
    }

    return pp(score, suffix, true);
  }

  if (score.type !== 'solo_score' && score.best_id == null) {
    return <span title={trans('scores.status.non_best')}>-</span>;
  }

  if (
    score.type === 'solo_score' &&
    (!score.preserve || !score.ranked || (score.pp == null && score.processed))
  ) {
    return <span title={trans('scores.status.no_pp')}>-</span>;
  }

  if (score.pp == null) {
    return (
      <span title={trans('scores.status.processing')}>
        <span className='fas fa-sync' />
      </span>
    );
  }

  return pp(score, suffix, false);
}
