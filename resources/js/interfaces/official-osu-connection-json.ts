// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

export default interface OfficialOsuConnectionJson {
  connected_at: string;
  avatar_url: string | null;
  can_disconnect: boolean;
  can_import: boolean;
  is_imported: boolean;
  is_removed: boolean;
  official_url: string;
  official_user_id: number;
  pending_review: boolean;
  restricted: boolean;
  review_status: string | null;
  token_unavailable: boolean;
  username_conflict: boolean;
  username: string;
}
