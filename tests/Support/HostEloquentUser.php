<?php

namespace D3Creative\Sentinel\Tests\Support;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** App\Models\User as Statamic's "Storing Users in a Database" guide sets it up. */
class HostEloquentUser extends Authenticatable
{
    protected $table = 'users';
    protected $guarded = [];
}
