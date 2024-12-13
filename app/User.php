<?php

namespace App;

use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'email',
        'password',
        'customer_id',
        'policy_ids',
        'secondary_customer_ids',
        'processing_policies',
        'logged_in_by_token'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'policy_ids' => 'array',
        'secondary_customer_ids' => 'array',
        'processing_policies' => 'array',
        'logged_in_by_token' => 'boolean'
    ];

    public function generateToken()
    {
        $this->api_token = Str::random(60);
        $this->save();

        return $this->api_token;
    }

    public function quotes() {
        $this->hasMany(Quote::class);
    }

    public function getCustomerIdForContractId($contractId)
    {
        $customerPolicies = array_filter($this->policy_ids, function($policyIds) use ($contractId) {
            return array_search($contractId, $policyIds) !== false;
        });

        return !empty($customerPolicies) ? array_keys($customerPolicies)[0] : null;
    }
}
