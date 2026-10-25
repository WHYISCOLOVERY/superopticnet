<?php
declare(strict_types=1);

$pendingAiSuggestions = (array) ($financeContext['data']['pending_ai_suggestions'] ?? []);
$recentAiConversations = (array) ($financeContext['data']['recent_ai_conversations'] ?? []);
$canWriteFinance = !empty($financeContext['data']['can_write']);
?>
<div data-finance-ai-assistant>
    <div class="portal-card-head">
        <div><strong>Maliyyə AI köməkçisi (pilot)</strong><small>20 illik təcrübəli maliyyə meneceri persona-sı, real sistem faktlarına əsaslanır. Sərbəst suala cavab verir; büdcə dəyişikliyi tövsiyəsi yalnız BAŞQA admin təsdiqi ilə tətbiq olunur.</small></div>
    </div>
    <?php if ($canWriteFinance): ?>
    <form class="portal-stacked-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
        <input type="hidden" name="action" value="ask_finance_ai_bot">
        <div class="portal-form-row"><textarea name="question" rows="3" required placeholder="Meselen: bu ay net pul axini nece? Yaxud: marketinq budcesini artirmagi teklif et."></textarea></div>
        <button class="portal-primary-btn" type="submit">Sual göndər</button>
    </form>
    <?php endif; ?>

    <?php if ($pendingAiSuggestions !== []): ?>
    <div class="portal-card portal-section-gap">
        <div class="portal-card-head"><strong>Gözləyən büdcə təklifləri</strong></div>
        <div class="spog-list">
            <?php foreach ($pendingAiSuggestions as $suggestion): ?>
                <div class="spog-list-item">
                    <strong><?= e((string) ($suggestion['category'] ?? '-')); ?> (<?= e((string) ($suggestion['period_month'] ?? '-')); ?>)</strong>
                    <small><?= e((string) ($suggestion['reasoning'] ?? '-')); ?></small>
                    <small>Hazırkı büdcə: <?= e(billing_money($suggestion['current_budgeted_amount'] ?? 0)); ?> → Təklif: <?= e(billing_money($suggestion['suggested_amount'] ?? 0)); ?></small>
                    <?php if ($canWriteFinance): ?>
                    <div class="portal-badge-list" style="margin-top:8px">
                        <form class="portal-inline-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                            <input type="hidden" name="action" value="resolve_finance_ai_suggestion">
                            <input type="hidden" name="suggestion_id" value="<?= e((string) ($suggestion['id'] ?? 0)); ?>">
                            <input type="hidden" name="decision" value="applied">
                            <button class="portal-primary-btn" type="submit">Tətbiq et</button>
                        </form>
                        <form class="portal-inline-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                            <input type="hidden" name="action" value="resolve_finance_ai_suggestion">
                            <input type="hidden" name="suggestion_id" value="<?= e((string) ($suggestion['id'] ?? 0)); ?>">
                            <input type="hidden" name="decision" value="dismissed">
                            <button class="portal-ghost-btn" type="submit">Rədd et</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="portal-card portal-section-gap">
        <div class="portal-card-head"><strong>Son sual-cavablar</strong></div>
        <div class="spog-list">
            <?php foreach ($recentAiConversations as $conversation): ?>
                <div class="spog-list-item">
                    <strong><?= e((string) ($conversation['question'] ?? '-')); ?></strong>
                    <p style="white-space:pre-wrap"><?= e((string) ($conversation['answer'] ?? '-')); ?></p>
                    <small><?= empty($conversation['llm_ok']) ? 'AI cavabı deyil (yerli LLM əlçatmaz idi, faktlara əsaslı xülasə göstərildi)' : 'AI cavabı'; ?> · <?= e((string) ($conversation['created_at'] ?? '-')); ?></small>
                </div>
            <?php endforeach; ?>
            <?php if ($recentAiConversations === []): ?><p class="finance-empty">Hələ sual verilməyib.</p><?php endif; ?>
        </div>
    </div>
</div>
