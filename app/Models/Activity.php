<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * activity_log — سجل التدقيق (إضافة فقط: triggers تمنع التعديل والحذف).
 * يرث مودل spatie ويضيف الأعمدة الإضافية. سجّله في config/activitylog.php → activity_model.
 */
class Activity extends SpatieActivity
{
    public function context(): BelongsTo
    {
        return $this->belongsTo(Context::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
