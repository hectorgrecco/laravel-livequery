<?php

namespace LiveQuery\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Order extends Model
{
    use SoftDeletes;

    protected $table = 'orders';

    public $timestamps = false;

    protected $guarded = [];
}
