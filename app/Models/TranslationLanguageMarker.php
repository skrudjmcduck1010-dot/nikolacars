<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'ua_marker',
    'ru_marker',
])]
class TranslationLanguageMarker extends Model {}
