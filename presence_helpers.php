<?php

const PRESENCE_IDLE_SECONDS = 600;
const PRESENCE_OFFLINE_SECONDS = 120;

function refreshPresenceStatuses(PDO $pdo): void
{
    $stmt = $pdo->prepare("
        UPDATE users
        SET status = CASE
            WHEN status = 'offline' THEN 'offline'
            WHEN last_active IS NULL
              OR TIMESTAMPDIFF(SECOND, last_active, NOW()) >= :offline_seconds
                THEN 'offline'
            WHEN status_mode = 'invisible' THEN 'hidden'
            WHEN status_mode = 'dnd' THEN 'away'
            WHEN status_mode = 'idle' THEN 'idle'
            WHEN last_interaction IS NULL
              OR TIMESTAMPDIFF(SECOND, last_interaction, NOW()) >= :idle_seconds
                THEN 'idle'
            ELSE 'online'
        END,
        last_active = last_active
    ");
    $stmt->execute([
        ':offline_seconds' => PRESENCE_OFFLINE_SECONDS,
        ':idle_seconds' => PRESENCE_IDLE_SECONDS,
    ]);
}

function effectivePresenceStatus(string $mode, string $clientState, ?string $lastInteraction): string
{
    return match ($mode) {
        'idle' => 'idle',
        'dnd' => 'away',
        'invisible' => 'hidden',
        default => $clientState !== 'active'
            || !$lastInteraction
            || (time() - strtotime($lastInteraction)) >= PRESENCE_IDLE_SECONDS
                ? 'idle'
                : 'online',
    };
}

function presencePayload(string $status, string $mode): array
{
    $labels = [
        'online' => '🟢 在線',
        'idle' => '🌙 閒置',
        'away' => '🔴 請勿打擾',
        'hidden' => '⚪ 隱形',
        'offline' => '⚪ 離線',
    ];

    return [
        'status' => $status,
        'mode' => $mode,
        'label' => $labels[$status] ?? '⚪ 離線',
        'idle_after_seconds' => PRESENCE_IDLE_SECONDS,
        'offline_after_seconds' => PRESENCE_OFFLINE_SECONDS,
    ];
}
