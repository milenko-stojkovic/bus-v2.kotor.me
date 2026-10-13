<?php

namespace App\Support;

/**
 * Maps an advance top-up outcome to the shared checkout_banner shape.
 * Copy is advance-specific. Classification reasons match {@see CheckoutResultFlash}.
 *
 * @phpstan-type Banner array{level: string, title_key: string, message_key: string, group: string}
 */
final class AdvanceTopupResultFlash
{
    public const GROUP = 'panel';

    /**
     * @return Banner
     */
    public static function success(): array
    {
        return self::wrap('success', 'advance_topup_success', 'advance_topup_success_detail');
    }

    /**
     * @return Banner
     */
    public static function unconfirmed(): array
    {
        return self::wrap('error', 'advance_topup_unconfirmed_title', 'advance_topup_unconfirmed_message');
    }

    /**
     * @return Banner
     */
    public static function forFailure(?string $resolutionReason): array
    {
        $reason = is_string($resolutionReason) ? trim($resolutionReason) : '';
        if ($reason === '') {
            $reason = 'unknown_gateway_error';
        }

        return match ($reason) {
            'transaction_expired' => self::wrap('error', 'advance_topup_expired_title', 'advance_topup_expired_message'),
            'user_cancelled' => self::wrap('error', 'advance_topup_cancelled_title', 'advance_topup_cancelled_message'),
            'insufficient_funds' => self::wrap('error', 'advance_topup_insufficient_funds_title', 'advance_topup_insufficient_funds_message'),
            'authorization_declined' => self::wrap('error', 'advance_topup_declined_title', 'advance_topup_declined_message'),
            '3ds_failed' => self::wrap('error', 'advance_topup_3ds_failed_title', 'advance_topup_3ds_failed_message'),
            'card_validation_error' => self::wrap('error', 'advance_topup_card_validation_title', 'advance_topup_card_validation_message'),
            'invalid_signature', 'malformed_callback' => self::unconfirmed(),
            default => self::wrap('error', 'advance_topup_failed', 'advance_topup_system_error_message'),
        };
    }

    /**
     * @return Banner
     */
    private static function wrap(string $level, string $titleKey, string $messageKey): array
    {
        return [
            'level' => $level,
            'title_key' => $titleKey,
            'message_key' => $messageKey,
            'group' => self::GROUP,
        ];
    }
}
