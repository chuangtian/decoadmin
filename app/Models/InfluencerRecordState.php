<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['organization_id', 'store_id', 'source_table_key', 'source_record_id', 'status'])]
class InfluencerRecordState extends Model {}
