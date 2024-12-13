<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Marketing extends Model
{
    protected $fillable = ['customer_id', 'sms_marketing', 'email_marketing', 'phone_marketing', 'post_marketing'];
}
