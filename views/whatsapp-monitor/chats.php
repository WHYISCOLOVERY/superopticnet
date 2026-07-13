<?php
declare(strict_types=1);

$chatRows = (array) ($context['chat_rows'] ?? []);
$selectedNumberId = (int) ($context['selected_number_id'] ?? 0);
$selectedChatId = (string) ($context['selected_chat_id'] ?? '');
$selectedChat = is_array($context['selected_chat'] ?? null) ? $context['selected_chat'] : [];
$chatMessages = (array) ($context['chat_messages'] ?? []);
$lastMessageId = (int) ($chatMessages !== [] ? ($chatMessages[array_key_last($chatMessages)]['id'] ?? 0) : 0);
$hasSelection = $selectedChatId !== '' && $selectedNumberId > 0;
$chatSelectionExplicit = !empty($context['chat_selection_explicit']);
$backUrl = whatsapp_monitor_url('chats', [], $monitorLang);
?>
<section class="portal-muted-card portal-section-gap wa-chat-monitor" data-wa-chat-monitor data-wa-monitor-lang="<?= e($monitorLang); ?>" data-has-selection="<?= $chatSelectionExplicit ? '1' : '0'; ?>">
    <div class="portal-card-head">
        <div><h3>WhatsApp cat monitoru</h3><small>Bagli sirket nomrelerine gelen ve gonderilen mesajlar real vaxtda gosterilir.</small></div>
        <span class="portal-pill"><?= e((string) count($chatRows)); ?> aktiv cat</span>
    </div>
    <?php if ($chatRows === []): ?>
        <?php billing_render_empty_state('Hele mesaj yoxdur', 'Nomre connected olduqdan sonra yeni gelen ve gonderilen mesajlar burada avtomatik gorunecek.', 'fa-comments'); ?>
    <?php else: ?>
        <div class="wa-chat-layout" data-wa-chat-layout>
            <aside class="wa-chat-list" aria-label="Cat siyahisi">
                <div class="wa-chat-search">
                    <label class="wa-chat-search-label" for="wa-chat-search-input">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        <span class="wa-visually-hidden">Cat axtar</span>
                    </label>
                    <input type="search" id="wa-chat-search-input" placeholder="Ad, nomre ve ya emekdas axtar" data-wa-chat-search autocomplete="off">
                </div>
                <div class="wa-chat-list-scroll" data-wa-chat-list>
                    <?php foreach ($chatRows as $chat): ?>
                        <?php
                        $chatId = (string) ($chat['chat_id'] ?? '');
                        $chatNumberId = (int) ($chat['number_id'] ?? 0);
                        $isActive = $selectedChatId === $chatId && $selectedNumberId === $chatNumberId;
                        $searchBlob = ((string) ($chat['contact_name'] ?? '')) . ' ' .
                            ((string) ($chat['company_number'] ?? '')) . ' ' .
                            ((string) ($chat['employee_name'] ?? ''));
                        ?>
                        <a class="wa-chat-link<?= $isActive ? ' is-active' : ''; ?>" data-wa-chat-search-item data-wa-chat-search-text="<?= e($searchBlob); ?>" data-number-id="<?= e((string) $chatNumberId); ?>" data-chat-id="<?= e($chatId); ?>" href="<?= e(whatsapp_monitor_url('chats', ['number_id' => $chatNumberId, 'chat_id' => $chatId], $monitorLang)); ?>"<?= $isActive ? ' aria-current="true"' : ''; ?>>
                            <span class="wa-chat-link-top">
                                <strong data-wa-chat-name><?= e((string) ($chat['contact_name'] ?? $chatId)); ?></strong>
                                <time data-wa-chat-time><?= e((string) ($chat['last_message_at'] ?? '')); ?></time>
                            </span>
                            <small><?= e((string) ($chat['company_number'] ?? '-')); ?> &middot; <?= e((string) ($chat['employee_name'] ?? '-')); ?></small>
                            <span class="wa-chat-link-preview" data-wa-chat-preview><?= e(mb_substr((string) ($chat['last_message'] ?? ''), 0, 80)); ?></span>
                            <span class="wa-chat-link-count" data-wa-chat-count><?= e((string) ($chat['message_count'] ?? 0)); ?> mesaj</span>
                        </a>
                    <?php endforeach; ?>
                    <p class="wa-chat-empty-filter" data-wa-chat-empty-filter hidden>Axtarisa uygun cat tapilmadi.</p>
                </div>
            </aside>
            <div class="wa-chat-thread" data-wa-chat-thread data-number-id="<?= e((string) $selectedNumberId); ?>" data-chat-id="<?= e($selectedChatId); ?>" data-last-message-id="<?= e((string) $lastMessageId); ?>">
                <?php if ($hasSelection): ?>
                    <div class="wa-chat-thread-head">
                        <a class="wa-chat-back" href="<?= e($backUrl); ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Siyahiya qayit</a>
                        <div class="wa-chat-thread-head-main">
                            <strong><?= e((string) ($selectedChat['contact_name'] ?? $selectedChatId)); ?></strong>
                            <small><?= e((string) ($selectedChat['company_number'] ?? '-')); ?> &middot; <?= e((string) ($selectedChat['employee_name'] ?? '-')); ?></small>
                        </div>
                        <span class="wa-live-status" data-wa-live-status data-state="live"><i class="fa-solid fa-circle" aria-hidden="true"></i> Canli</span>
                        <span class="wa-chat-new-badge" data-wa-new-badge hidden>Yeni mesaj</span>
                    </div>
                    <p class="wa-chat-thread-note">Yalniz monitorinq goruntusudur; buradan mesaj gonderilmir.</p>
                    <div class="wa-chat-messages" data-wa-chat-messages role="log" aria-live="polite">
                        <?php if ($chatMessages === []): ?>
                            <?php billing_render_empty_state('Bu catda hele mesaj yoxdur', 'Yeni mesaj gelende burada avtomatik gorunecek.', 'fa-comment-dots'); ?>
                        <?php else: ?>
                            <?php foreach ($chatMessages as $message): ?>
                                <?php $direction = (string) ($message['direction'] ?? 'inbound'); ?>
                                <article class="wa-message is-<?= e($direction); ?>" data-message-id="<?= e((string) ($message['id'] ?? 0)); ?>">
                                    <div><?= e((string) ($message['message_body'] ?? '')); ?><?php if (!empty($message['has_media'])): ?><small>[Media: <?= e((string) ($message['message_type'] ?? 'file')); ?>]</small><?php endif; ?></div>
                                    <time><?= e((string) ($message['occurred_at'] ?? '')); ?></time>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php billing_render_empty_state('Cat secin', 'Sol terefden bir yazisma secin ki, mesajlar burada gorunsun.', 'fa-comments'); ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
