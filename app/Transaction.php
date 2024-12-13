<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    public function quote()
    {
        return $this->belongsTo( Quote::class, 'quote_id', 'id');
    }
}
