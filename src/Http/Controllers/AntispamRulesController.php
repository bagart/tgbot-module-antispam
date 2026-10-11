<?php

namespace BAGArt\TelegramBotAntispam\Http\Controllers;

use BAGArt\TelegramBotAntispam\Events\AntispamAuditEvent;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotAntispam\Engine\DbRuleOverrides;
use BAGArt\TelegramBotAntispam\Models\AntispamRuleModel;
use BAGArt\TelegramBotAntispam\Rules\RuleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AntispamRulesController
{
    public function __construct(
        private readonly DbRuleOverrides $dbRuleOverrides,
    ) {
    }

    public function index(): Response
    {
        $registry = app(RuleRegistry::class);

        return Inertia::render('antispam/rules', [
            'dbRules' => AntispamRuleModel::query()->orderBy('bot_id')->orderBy('name')->get(),
            'builtinRules' => collect(iterator_to_array($registry))
                ->map(fn ($rule): array => ['id' => $rule->id(), 'group' => $rule->group()])
                ->values(),
            'groups' => RuleRegistry::GROUPS,
            'bots' => TgBot::query()->orderBy('bot_id')->get(['bot_id']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $rule = AntispamRuleModel::query()->create(
            $this->validated($request) + ['created_by' => $request->user()?->email],
        );
        $this->dbRuleOverrides->invalidate();

        event(new AntispamAuditEvent(
            AntispamAuditEvent::RULE_CREATED,
            botId: $rule->bot_id,
            subjectType: 'antispam_rule',
            subjectId: $rule->id,
            actorId: $request->user()?->id,
            context: ['name' => (string) $rule->name, 'groupId' => (string) $rule->group_id],
        ));

        return to_route('antispam.rules.index');
    }

    public function update(Request $request, AntispamRuleModel $rule): RedirectResponse
    {
        $validated = $this->validated($request);

        $rule->update($validated);
        $this->dbRuleOverrides->invalidate();

        event(new AntispamAuditEvent(
            AntispamAuditEvent::RULE_CHANGED,
            botId: $rule->bot_id,
            subjectType: 'antispam_rule',
            subjectId: $rule->id,
            actorId: $request->user()?->id,
            newState: $validated,
        ));

        return to_route('antispam.rules.index');
    }

    public function destroy(AntispamRuleModel $rule): RedirectResponse
    {
        $rule->delete();
        $this->dbRuleOverrides->invalidate();

        event(new AntispamAuditEvent(
            AntispamAuditEvent::RULE_DELETED,
            botId: $rule->bot_id,
            subjectType: 'antispam_rule',
            subjectId: $rule->id,
            actorId: auth()->user()?->id,
            context: ['name' => (string) $rule->name],
        ));

        return to_route('antispam.rules.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'bot_id' => ['nullable', 'string', 'max:20', Rule::exists('tg_bots', 'bot_id')],
            'name' => ['required', 'string', 'max:100'],
            'group_id' => ['required', 'string', Rule::in(array_keys(RuleRegistry::GROUPS))],
            'type' => ['required', 'string', Rule::in(['regex', 'keyword', 'url', 'window', 'repeat', 'size'])],
            'config' => ['nullable', 'array'],
            'score_weight' => ['required', 'integer', 'min:1', 'max:200'],
            'severity' => ['required', 'string', Rule::in(['info', 'low', 'medium', 'high', 'critical'])],
            'kind' => ['required', 'string', Rule::in(['soft', 'hard'])],
            'priority' => ['required', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'cooldown_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ]);

        // Unchecked checkboxes are omitted from the payload
        $validated['is_active'] ??= false;

        return $validated;
    }
}
