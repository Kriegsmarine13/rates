<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Table('rates', timestamps: false)]
#[Fillable(['currency', 'rate', 'date', 'nominal'])]
class Rate extends Model
{
    protected $primaryKey = 'id';
}
