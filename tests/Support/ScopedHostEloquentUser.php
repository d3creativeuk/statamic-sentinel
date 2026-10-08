<?php

namespace D3Creative\Sentinel\Tests\Support;

/**
 * A host model with a query scope named isSuper: `$model->isSuper()` then
 * returns a (truthy) Builder instead of throwing.
 */
class ScopedHostEloquentUser extends HostEloquentUser
{
    public function scopeIsSuper($query)
    {
        return $query->where('super', true);
    }
}
