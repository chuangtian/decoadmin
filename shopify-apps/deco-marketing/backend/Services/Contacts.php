<?php

namespace DecoMarketing\Services;

use App\Models\Store;
use Carbon\CarbonImmutable;
use DecoMarketing\Models\Contact;
use DecoMarketing\Models\Enrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Contacts
{
    public static function hash(string $email): string
    {
        return hash_hmac('sha256', strtolower(trim($email)), (string) config('app.key'));
    }

    public function upsert(Store $store, array $data, string $source): Contact
    {
        app(Guard::class)->store($store);
        $email = strtolower(trim($data['email'] ?? ''));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            throw ValidationException::withMessages(['email' => '请输入有效邮箱。']);
        }
        $consent = $data['consent'] ?? 'not_subscribed';
        if (! in_array($consent, ['subscribed', 'not_subscribed', 'unsubscribed', 'pending'], true)) {
            throw ValidationException::withMessages(['consent' => '订阅状态无效。']);
        }
        if ($consent === 'subscribed' && empty($data['consent_at'])) {
            throw ValidationException::withMessages(['consent_at' => '导入订阅用户必须提供同意时间。']);
        }
        $at = isset($data['consent_at']) ? CarbonImmutable::parse($data['consent_at']) : now();
        if ($at->isFuture()) {
            throw ValidationException::withMessages(['consent_at' => '同意时间不能在未来。']);
        }

        return DB::transaction(function () use ($store, $data, $source, $email, $consent, $at) {
            Contact::forStore($store)->firstOrCreate(['email_hash' => self::hash($email)], [
                'organization_id' => $store->organization_id, 'store_id' => $store->id, 'email_encrypted' => $email, 'source' => $source,
            ]);
            $contact = Contact::forStore($store)->where('email_hash', self::hash($email))->lockForUpdate()->firstOrFail();
            $values = ['email_encrypted' => $email];
            if (isset($data['name'])) {
                $values['name_encrypted'] = mb_substr($data['name'], 0, 160);
            }
            if (isset($data['customer_id'])) {
                $values['customer_id'] = $data['customer_id'];
            }
            if (isset($data['orders_count'])) {
                $values['orders_count'] = max(0, (int) $data['orders_count']);
            }
            // A sync/import cannot silently undo a withdrawal. Explicit new consent uses the subscribe endpoint.
            if ((! $contact->consent_at || $at->gte($contact->consent_at)) && ! ($contact->consent === 'unsubscribed' && $consent === 'subscribed' && $source !== 'storefront')) {
                $values['consent'] = $consent;
                $values['consent_at'] = $at;
                if ($consent === 'subscribed' && ! $contact->first_subscribed_at) {
                    $values['first_subscribed_at'] = $at;
                }
            }
            $contact->update($values);
            if ($contact->consent !== 'subscribed' || $contact->suppressed) {
                $this->stop($contact, $contact->suppressed ? 'suppressed' : 'not_subscribed');
            }

            return $contact;
        });
    }

    public function suppress(Contact $contact, string $reason = 'manual'): void
    {
        app(Guard::class)->store($contact->store);
        DB::transaction(function () use ($contact, $reason) {
            $locked = Contact::whereKey($contact->id)->lockForUpdate()->firstOrFail();
            $locked->update(['suppressed' => true, 'suppression_reason' => $reason]);
            $this->stop($locked, 'suppressed');
        });
    }

    public function unsubscribe(Contact $contact): void
    {
        app(Guard::class)->store($contact->store);
        DB::transaction(function () use ($contact) {
            $locked = Contact::whereKey($contact->id)->lockForUpdate()->firstOrFail();
            $locked->update(['consent' => 'unsubscribed', 'consent_at' => now()]);
            $this->stop($locked, 'unsubscribed');
        });
    }

    private function stop(Contact $contact, string $reason): void
    {
        Enrollment::forStore($contact->store)->where('contact_id', $contact->id)->where('status', 'active')->update(['status' => 'stopped', 'stop_reason' => $reason, 'next_at' => null]);
    }

    public function parseImport(string $csv): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        if (count($lines) > 501) {
            throw ValidationException::withMessages(['csv' => '每批最多 500 行。']);
        }
        $header = str_getcsv(array_shift($lines), ',', '"', '');
        if ($header !== ['email', 'name', 'consent', 'consent_at']) {
            throw ValidationException::withMessages(['csv' => '表头必须为 email,name,consent,consent_at']);
        }
        $rows = [];
        $errors = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, ',', '"', '');
            if (count($cells) !== 4 || ! filter_var($cells[0], FILTER_VALIDATE_EMAIL) || ! in_array($cells[2], ['subscribed', 'not_subscribed', 'unsubscribed', 'pending'])) {
                $errors[] = '第 '.($i + 2).' 行格式无效';

                continue;
            }
            try {
                $at = $cells[3] !== '' ? CarbonImmutable::parse($cells[3]) : null;
            } catch (\Throwable) {
                $at = null;
            }
            if (($cells[2] === 'subscribed' && ! $at) || ($at && $at->isFuture())) {
                $errors[] = '第 '.($i + 2).' 行同意时间无效';

                continue;
            }
            $rows[] = array_combine($header, [$cells[0], $cells[1], $cells[2], $at?->toIso8601String()]);
        }

        return ['rows' => $rows, 'errors' => $errors, 'total' => count($rows)];
    }
}
