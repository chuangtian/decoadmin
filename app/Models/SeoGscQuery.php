<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'store_id', 'query_hash', 'query'])]
class SeoGscQuery extends Model
{
    use ScopesToOrganizationStore;
}
