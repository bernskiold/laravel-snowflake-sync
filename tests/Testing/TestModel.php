<?php

namespace Bernskiold\LaravelSnowflakeSync\Tests\Testing;

use Bernskiold\LaravelSnowflakeSync\Concerns\SyncsToSnowflake;
use Illuminate\Database\Eloquent\Model;

class TestModel extends Model
{
    use SyncsToSnowflake;

    protected $table = 'test_models';

    protected $fillable = ['name'];

    public $timestamps = true;
}
