// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

import CountryJson from './country-json';
import Ruleset from './ruleset';
import UserBadgeJson from './user-badge-json';
import { Grade } from './user-statistics-json';

interface OfficialProfileImportStatisticsJson {
  accuracy: number;
  count_100: number;
  count_300: number;
  count_50: number;
  count_miss: number;
  grade_counts: Record<Grade, number>;
  hit_accuracy: number;
  level: number;
  maximum_combo: number;
  play_count: number;
  play_time: number;
  ranked_score: number;
  total_hits: number;
  total_score: number;
}

export default interface OfficialProfileImportJson {
  applied_at: string | null;
  official_url: string;
  official_user_id: number;
  profile: {
    avatar_url: string | null;
    badges: UserBadgeJson[];
    badges_count: number;
    country: CountryJson | null;
    cover_url: string | null;
    is_supporter: boolean;
    join_date: string | null;
    page_html: string | null;
    title: string | null;
    username: string | null;
  };
  statistics: {
    current: OfficialProfileImportStatisticsJson | null;
    modes: Partial<Record<Ruleset, OfficialProfileImportStatisticsJson>>;
  };
}
