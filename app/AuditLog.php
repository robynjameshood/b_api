<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = ['user_id', 'text', 'function'];
    protected $connection = 'mysql';
    protected $primaryKey = 'id';
    protected $table = 'audit_logs';
    

    public $timestamps = false;
}
