<?php
/**
 * Created by PhpStorm.
 * User: Chris.Williams
 * Date: 22/01/2019
 * Time: 11:34
 */

namespace App;

use Illuminate\Database\Eloquent\Model;

class Pack extends Model
{
    public function scopeFindPackId($query,$packId)
    {
        return $query->where('pack_id',$packId)->first();
    }
}
