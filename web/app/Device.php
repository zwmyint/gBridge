<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    /**
     * Model stored in table 'device'
     */
    protected $table = 'device';
    /**
     * The primary key is here 'device_id' not the default 'id'
     */
    protected $primaryKey = 'device_id';
    /**
     * Timestamps are unnecessary
     */
    public $timestamps = false;

    /**
     * Tables that are always joined
     * Not necessary? Don't unterstand how that works :)
     */
    //public $with = ['deviceType', 'traits'];

    public function deviceType(){
        return $this->belongsTo('App\DeviceType', 'devicetype_id');
    }

    public function traits(){
        //This is a m:n relation, joined by the table trait
        return $this->belongsToMany('App\TraitType', 'trait', 'device_id', 'traittype_id')->withPivot('trait_id', 'config', 'mqttActionTopic', 'mqttStatusTopic');
    }

    public function user(){
        return $this->belongsTo('App\User', 'user_id');
    }
    
}
