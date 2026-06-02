<?php

namespace Bernskiold\LaravelSnowflakeSync\Tests\Testing;

use Bernskiold\LaravelSnowflakeSync\Concerns\SyncsToSnowflake;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SoftDeleteTestModel extends Model
{
    use SoftDeletes, SyncsToSnowflake;

    protected $table = 'soft_delete_test_models';

    protected $fillable = ['name'];

    public $timestamps = true;
}
