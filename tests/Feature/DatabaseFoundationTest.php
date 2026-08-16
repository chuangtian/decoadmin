<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_foundation_tables_exist(): void
    {
        $tables = [
            'users',
            'organizations',
            'organization_users',
            'stores',
            'store_members',
            'roles',
            'permissions',
            'role_permissions',
            'user_roles',
            'apps',
            'shopify_connections',
            'app_installations',
            'oauth_states',
            'webhook_events',
            'sync_jobs',
            'audit_logs',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_key_json_soft_delete_and_tracking_columns_exist(): void
    {
        $expectedColumns = [
            'users' => ['status', 'timezone', 'locale', 'metadata', 'deleted_at'],
            'organizations' => ['settings', 'deleted_at'],
            'stores' => ['shopify_domain', 'settings', 'deleted_at'],
            'roles' => ['slug', 'is_system', 'deleted_at'],
            'apps' => ['client_secret_encrypted', 'scopes', 'redirect_uris', 'deleted_at'],
            'shopify_connections' => ['access_token_encrypted', 'scopes', 'metadata', 'last_api_check', 'last_verified_at', 'last_error', 'last_error_at', 'deleted_at'],
            'oauth_states' => ['state_hash', 'expires_at', 'consumed_at'],
            'webhook_events' => ['webhook_id', 'headers', 'payload', 'processed_at'],
            'sync_jobs' => ['uuid', 'payload', 'result', 'deleted_at'],
            'audit_logs' => ['uuid', 'old_values', 'new_values', 'metadata'],
        ];

        foreach ($expectedColumns as $table => $columns) {
            $this->assertTrue(
                Schema::hasColumns($table, $columns),
                "Missing one or more expected columns on {$table}",
            );
        }
    }

    public function test_critical_unique_indexes_are_present(): void
    {
        $expectedIndexes = [
            'organizations' => 'organizations_code_unique',
            'organization_users' => 'org_users_org_user_unique',
            'stores' => 'stores_shopify_domain_unique',
            'store_members' => 'store_members_store_user_unique',
            'roles' => 'roles_org_slug_unique',
            'permissions' => 'permissions_slug_unique',
            'user_roles' => 'user_roles_scope_user_role_unique',
            'apps' => 'apps_handle_unique',
            'shopify_connections' => 'shopify_connections_store_id_unique',
            'app_installations' => 'app_installations_app_store_unique',
            'oauth_states' => 'oauth_states_state_hash_unique',
            'webhook_events' => 'webhook_events_webhook_id_unique',
            'sync_jobs' => 'sync_jobs_uuid_unique',
            'audit_logs' => 'audit_logs_uuid_unique',
        ];

        foreach ($expectedIndexes as $table => $expectedIndex) {
            $indexNames = collect(Schema::getIndexes($table))->pluck('name');

            $this->assertTrue(
                $indexNames->contains($expectedIndex),
                "Missing unique index {$expectedIndex} on {$table}",
            );
        }
    }
}
