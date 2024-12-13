<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Quote extends Model
{
    protected $fillable = ['completed', 'completed_at', 'refunded'];

    protected $casts = [
        'completed_at' => 'datetime'
    ];

    public function user() {
        return $this->belongsTo(User::class);
    }
}
