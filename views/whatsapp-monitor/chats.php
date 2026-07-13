<?php
declare(strict_types=1);

$chatRows = (array) ($context['chat_rows'] ?? []);
$selectedChatId = (string) ($context['selected_chat_id'] ?? '');
$chatMessages = (array) ($context['chat_messages'] ?? []);
$lastMessageId = (int) ($chatMessages !== [] ? ($chatMessages[array_key_last($chatMessages)]['id'] ?? 0) : 0);
?>
<section class="portal-muted-card portal-section-gap">
    <div class="portal-card-head">
        <div><h3>WhatsApp cat monitoru</h3><small>Bagli sirket nomrelerine gelen ve gonderilen mesajlar real vaxtda gosterilir.</small></div>
        <span class="portal-pill"><?= e((string) count($chatRows)); ?> aktiv cat</span>
    </div>
    <?php if ($chatRows === []): ?>
        <?php billing_render_empty_state('Hele mesaj yoxdur', 'Nomre connected olduqdan sonra yeni gelen ve gonderilen mesajlar burada avtomatik gorunecek.', 'fa-comments'); ?>
    <?php else: ?>
        <div class="wa-chat-layout">
            <aside class="wa-chat-list" aria-label="Cat siyahisi">
                <?php foreach ($chatRows as $chat): ?>
                    <?php $chatId = (string) ($chat['chat_id'] ?? ''); ?>
                    <a class="wa-chat-link<?= $selectedChatId === $chatId ? ' is-active' : ''; ?>" href="<?= e(whatsapp_monitor_url('chats', ['chat_id' => $chatId], $monitorLang)); ?>">
                        <strong><?= e((string) ($chat['contact_name'] ?? $chatId)); ?></strong>
                        <small><?= e((string) ($chat['company_number'] ?? '-')); ?> / <?= e((string) ($chat['employee_name'] ?? '-')); ?></small>
                        <span><?= e(mb_substr((string) ($chat['last_message'] ?? ''), 0, 80)); ?></span>
                        <time><?= e((string) ($chat['last_message_at'] ?? '')); ?></time>
                    </a>
                <?php endforeach; ?>
            </aside>
            <div class="wa-chat-thread" data-wa-chat-thread data-chat-id="<?= e($selectedChatId); ?>" data-last-message-id="<?= e((string) $lastMessageId); ?>">
                <div class="wa-chat-thread-head"><strong><?= e((string) (($chatRows[array_search($selectedChatId, array_column($chatRows, 'chat_id'), true)]['contact_name'] ?? $selectedChatId))); ?></strong><small>Yalniz monitorinq goruntusudur; buradan mesaj gonderilmir.</small></div>
                <div class="wa-chat-messages" data-wa-chat-messages>
                    <?php foreach ($chatMessages as $message): ?>
                        <?php $direction = (string) ($message['direction'] ?? 'inbound'); ?>
                        <article class="wa-message is-<?= e($direction); ?>" data-message-id="<?= e((string) ($message['id'] ?? 0)); ?>">
                            <div><?= e((string) ($message['message_body'] ?? '')); ?><?php if (!empty($message['has_media'])): ?><small>[Media: <?= e((string) ($message['message_type'] ?? 'file')); ?>]</small><?php endif; ?></div>
                            <time><?= e((string) ($message['occurred_at'] ?? '')); ?></time>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
