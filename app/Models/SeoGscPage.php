<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'store_id', 'page_hash', 'page', 'is_blog'])]
class SeoGscPage extends Model
{
    use ScopesToOrganizationStore;

    protected function casts(): array
    {
        return ['is_blog' => 'boolean'];
    }
}
