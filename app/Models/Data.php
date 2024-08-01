<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Data extends Model
{
    use HasFactory;

    protected $table = 'form_values';

    protected $fillable = [
        'id',
        'json',
        'petugas',
        'created_at',
        'datetime_masuk',
        'datetime_pengerjaan',
        'datetime_selesai',
        'status',
        'is_pending'
    ];

    protected $casts = [
        'json' => 'array'
    ];

    protected $dates = [
        'created_at',
        'datetime_masuk',
        'datetime_pengerjaan',
        'datetime_selesai',
    ];

}
