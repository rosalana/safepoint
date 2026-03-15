<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Enums\RequireStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $title
 * @property string $body
 * @property bool $published
 * @property int $user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Post extends Model
{
    protected $fillable = [
        'title',
        'body',
        'published',
        'user_id',
        'status',
    ];

    protected $casts = [
        'published' => 'boolean',
        'status' => PostStatus::class,
    ];

    protected $appends = [
        'require_status'
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getRequireStatusAttribute(): RequireStatus | null
    {
        if ($this->published) {
            return RequireStatus::REQUIRED;
        } else if ($this->status === PostStatus::DRAFT) {
            return RequireStatus::OPTIONAL;
        } else {
            return null;
        }
    }
}
