<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ElectraOnePageQuote extends Model
{
    protected $fillable = [
        'quote_hero_id',
        'policy_number',
        'effective_date',
        'commission',
        'aprp',
        'annual_difference',
        'admin_charge_discount'
    ];
    //
}
