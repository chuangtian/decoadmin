<?php

namespace App\Http\Controllers;

use App\Services\SystemSettingsService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SystemSettingsController extends Controller
{
    public function __construct(
        private SystemSettingsService $settings,
        private CurrentOrganization $currentOrganization,
        private CurrentStore $currentStore,
    ) {}

    public function index(Request $request): Response
    {
        $isSuperAdmin = $request->user()->isSuperAdmin();

        return $this->render($request, 'System/Settings', 'general', [
            'timezones' => collect(timezone_identifiers_list())
                ->filter(fn (string $timezone): bool => $timezone === 'UTC' || str_contains($timezone, '/'))
                ->values()
                ->all(),
            'aiSettings' => $isSuperAdmin
                ? $this->settings->sectionForFrontend('student_ai', false)
                : null,
            'canUpdateAi' => $isSuperAdmin,
        ]);
    }

    public function mail(Request $request): Response
    {
        return $this->render($request, 'System/MailSettings', 'mail');
    }

    public function feishu(Request $request): Response
    {
        return $this->render($request, 'System/FeishuSettings', 'feishu');
    }

    /** @param array<string, mixed> $extra */
    private function render(Request $request, string $component, string $section, array $extra = []): Response
    {
        $organization = $this->currentOrganization->require();
        $canUpdate = $request->user()->hasPermission(
            'system.settings.update',
            $organization,
            $this->currentStore->get(),
        );

        return Inertia::render($component, [
            'settings' => $this->settings->sectionForFrontend($section, $canUpdate),
            'canUpdate' => $canUpdate,
            ...$extra,
        ]);
    }

    public function updateGeneral(Request $request): RedirectResponse
    {
        $values = $request->validate([
            'platform_name' => ['required', 'string', 'max:80'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'locale' => ['required', Rule::in(['zh_CN', 'en'])],
        ]);

        return $this->save('general', $values, $request, '基础设置已保存。');
    }

    public function updateMail(Request $request): RedirectResponse
    {
        $values = $request->validate([
            'enabled' => ['required', 'boolean'],
            'host' => ['nullable', 'required_if:enabled,true', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1000'],
            'from_address' => ['nullable', 'required_if:enabled,true', 'email:rfc', 'max:255'],
            'from_name' => ['nullable', 'required_if:enabled,true', 'string', 'max:120'],
            'timeout' => ['required', 'integer', 'between:1,60'],
        ]);

        return $this->save('mail', $values, $request, '系统发信邮箱已保存。');
    }

    public function updateFeishu(Request $request): RedirectResponse
    {
        $values = $request->validate([
            'enabled' => ['required', 'boolean'],
            'app_id' => ['nullable', 'required_if:enabled,true', 'string', 'max:255'],
            'app_secret' => ['nullable', 'string', 'max:1000'],
            'verification_token' => ['nullable', 'string', 'max:1000'],
            'encrypt_key' => ['nullable', 'string', 'max:1000'],
            'bot_webhook_url' => ['nullable', 'url:https', 'max:2000'],
        ]);

        return $this->save('feishu', $values, $request, '飞书设置已保存。');
    }

    public function updateStudentAi(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $values = $request->validate([
            'gemini_api_key' => ['nullable', 'string', 'max:2000'],
            'gemini_model' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'auto_approval_threshold' => ['required', 'numeric', 'between:0,100'],
        ]);

        return $this->save('student_ai', $values, $request, 'AI 学生证识别设置已保存。');
    }

    /** @param array<string, mixed> $values */
    private function save(string $section, array $values, Request $request, string $message): RedirectResponse
    {
        $this->settings->update(
            $section,
            $values,
            $request->user(),
            $this->currentOrganization->require(),
            $this->currentStore->get(),
        );

        return back()->with('success', $message);
    }
}
