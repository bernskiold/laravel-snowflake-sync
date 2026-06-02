<?php

namespace Bernskiold\LaravelSnowflakeSync\Tests\Testing;

use Bernskiold\LaravelSnowflakeSync\Concerns\SyncsToSnowflake;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SoftDeleteRemovesTestModel extends Model
{
    use SoftDeletes, SyncsToSnowflake;

    protected $table = 'soft_delete_test_models';

    protected $fillable = ['name'];

    public $timestamps = true;

    public function removeFromSnowflakeOnSoftDelete(): bool
    {
        return true;
    }
}
