<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReportExport extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'kind', 'report_type', 'partner_id', 'filters', 'title', 'status', 'path'];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }
}
