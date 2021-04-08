<?php

declare(strict_types=1);

namespace DrewRoberts\Blog\Models;

use Carbon\Carbon;
use DrewRoberts\Blog\Exceptions\InvalidSlugException;
use DrewRoberts\Blog\Exceptions\NestingTooDeepException;
use DrewRoberts\Blog\Exceptions\HasChildrenException;
use DrewRoberts\Blog\Traits\HasPageViews;
use DrewRoberts\Blog\Traits\Publishable;
use DrewRoberts\Media\Models\Image;
use DrewRoberts\Media\Models\Video;
use DrewRoberts\Media\Traits\HasMedia;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Nova\Nova;
use Tipoff\Authorization\Models\User;
use Tipoff\Seo\Models\Webpage;
use Tipoff\Support\Models\BaseModel;
use Tipoff\Support\Traits\HasCreator;
use Tipoff\Support\Traits\HasPackageFactory;
use Tipoff\Support\Traits\HasUpdater;

/**
 * @property int id
 * @property string slug
 * @property string title
 * @property bool location_based
 * @property Page parent
 * @property bool is_leaf
 * @property bool is_root
 * @property string|null path
 * @property int depth
 * @property string content
 * @property Webpage webpage
 * @property int pageviews
 * @property string description
 * @property string ogdescription
 * @property Image image
 * @property Image ogimage
 * @property Video video
 * @property User author
 * @property Carbon published_at
 * @property Carbon deleted_at
 * @property Carbon created_at
 * @property Carbon updated_at
 * // Raw Relations
 * @property int|null parent_id
 * @property int|null webpage_id
 * @property int|null image_id
 * @property int|null ogimage_id
 * @property int|null video_id
 * @property int author_id
 * @property int creator_id
 * @property int updater_id
 */
class Page extends BaseModel
{
    use SoftDeletes,
        HasCreator,
        HasUpdater,
        HasPackageFactory,
        Publishable,
        HasMedia,
        HasPageViews;

    protected $casts = [
        'location_based' => 'boolean',
        'parent_id' => 'integer',
        'webpage_id' => 'integer',
        'image_id' => 'integer',
        'ogimage_id' => 'integer',
        'video_id' => 'integer',
        'author_id' => 'integer',
        'creator_id' => 'integer',
        'updater_id' => 'integer',
        'published_at' => 'datetime',
    ];

    protected $fillable = [
        'parent_id',
        'slug',
        'title'
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function (Page $page) {
            $page->validateSlug();
            // Can specify a different author for a page than Auth user
            $page->author_id = $page->author_id ?: auth()->user()->id;
        });

        static::deleting(function (Page $page) {
            throw_unless($page->is_leaf, HasChildrenException::class);
        });
    }

    private function validateSlug(): void
    {
        if ($this->is_root) {
            InvalidSlugException::checkRootSlugRestrictions($this->slug);

            // Prevent root pages from having same slug as topics
            throw_if(Topic::query()->where('slug', '=', $this->slug)->count(), InvalidSlugException::class);
        }

        // Prevent pages from having same slug as other pages at same nesting level
        $count = Page::query()
            ->where(function ($builder) {
                if ($this->id) {
                    $builder->where('id', '<>', $this->id);
                }
                if ($this->parent_id) {
                    $builder->where('parent_id', '=', $this->parent_id);
                } else {
                    $builder->whereNull('parent_id');
                }
            })
            ->where('slug', '=', $this->slug)
            ->count();
        throw_if($count, InvalidSlugException::class);
    }

    public function getRouteKeyName()
    {
        return 'slug';
    }

    public function getPathAttribute(): ?string
    {
        return $this->is_leaf ? '/' . implode('/', $this->getParentPath()) : null;
    }

    public function getIsRootAttribute(): bool
    {
        return $this->parent_id === null;
    }

    public function getIsLeafAttribute(): bool
    {
        return $this->children()->count() === 0;
    }

    public function getDepthAttribute(): int
    {
        return count($this->getParentPath());
    }

    private function getParentPath(): array
    {
        $path = [];
        $parent = $this;
        while ($parent) {
            $path[] = $parent;
            $parent = $parent->parent;
        }

        return array_reverse($path);
    }

    public function author()
    {
        return $this->belongsTo(app('user'), 'author_id');
    }

    public function market()
    {
        return $this->hasOne(app('market'));
    }

    public function location()
    {
        return $this->hasOne(app('location'));
    }

    public function parent()
    {
        return $this->belongsTo(app('page'), 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(app('page'), 'parent_id');
    }

    public function setParent(Page $parent): self
    {
        throw_if($parent->depth > 2, NestingTooDeepException::class);

        $this->parent_id = $parent->id;
        $this->save();

        return $this;
    }
}
