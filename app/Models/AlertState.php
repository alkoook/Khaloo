<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertState extends Model
{
    protected $fillable = ['subject_type', 'subject_id', 'alert_type', 'active', 'last_triggered_at'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_triggered_at' => 'datetime'];
    }
}
