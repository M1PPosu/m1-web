<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Transformers;

use App\Models\UserBadge;

class UserBadgeTransformer extends TransformerAbstract
{
    public function transform(UserBadge $badge)
    {
        $imageUrl = $badge->imageUrl();

        return [
            'awarded_at' => json_time($badge->awarded),
            'description' => $badge->description,
            'image@2x_url' => retinaify($imageUrl),
            'image_url' => $imageUrl,
            'url' => $badge->url,
        ];
    }
}
