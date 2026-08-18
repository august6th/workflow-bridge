<?php

namespace August6th\WorkflowBridge\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowCallbackDelivery extends Model
{
    protected $table = 'workflow_callback_deliveries';

    protected $guarded = [];

    public $timestamps = true;

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';
}
