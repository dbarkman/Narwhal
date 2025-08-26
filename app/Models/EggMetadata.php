<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EggMetadata extends Model
{
    use HasFactory;

    protected $table = 'egg_metadata';

    protected $fillable = [
        'nest_id',
        'nest_name',
        'egg_id',
        'egg_name',
        'port_min',
        'port_max',
        'environment_json',
        'is_active',
    ];

    protected $casts = [
        'environment_json' => 'array',
        'is_active' => 'boolean',
    ];
}


