<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ral extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'name', 'price', 'code', 'time',
    ];
}
