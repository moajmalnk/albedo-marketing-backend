<?php

namespace App\Support;

use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;

/**
 * Channel rules for marketing dashboards — keep in sync with {@see \App\Support\LeadChannelClassifier}
 * on the frontend (leadMappers) when classifying single leads.
 */
class LeadChannelClassifier
{
    public const WHATSAPP = 'WhatsApp';

    public const FORM = 'Form';

    public const CALL = 'Call';

    public const MESSAGE = 'Message';

    /** @return self::WHATSAPP|self::FORM|self::CALL|self::MESSAGE */
    public static function classify(Lead $lead): string
    {
        return self::classifyFromRow(
            $lead->whatsapp_id,
            $lead->source_code,
            $lead->connected_by
        );
    }

    /**
     * @return self::WHATSAPP|self::FORM|self::CALL|self::MESSAGE
     */
    public static function classifyFromRow(?string $whatsappId, ?string $sourceCode, ?string $connectedBy): string
    {
        $sc = strtolower(trim((string) $sourceCode));
        $cb = strtolower(trim((string) $connectedBy));

        if ($whatsappId !== null && $whatsappId !== '') {
            return self::WHATSAPP;
        }
        if ($sc !== '' && (str_contains($sc, 'whatsapp') || $sc === 'wa' || str_starts_with($sc, 'wa_'))) {
            return self::WHATSAPP;
        }
        if ($cb !== '' && str_contains($cb, 'call')) {
            return self::CALL;
        }
        if ($sc !== '' && str_contains($sc, 'call')) {
            return self::CALL;
        }
        if ($sc !== '' && (str_contains($sc, 'message') || str_contains($sc, 'sms'))) {
            return self::MESSAGE;
        }
        if ($cb !== '' && (str_contains($cb, 'message') || str_contains($cb, 'sms'))) {
            return self::MESSAGE;
        }

        return self::FORM;
    }

    public static function applyChannelFilter(Builder $query, string $channel): void
    {
        $query->where(function (Builder $q) use ($channel) {
            match ($channel) {
                self::WHATSAPP => $q->whereNotNull('whatsapp_id')
                    ->orWhereRaw('LOWER(COALESCE(source_code, "")) LIKE ?', ['%whatsapp%'])
                    ->orWhere('source_code', 'whatsapp'),
                self::CALL => $q->whereRaw('LOWER(COALESCE(connected_by, "")) LIKE ?', ['%call%'])
                    ->orWhereRaw('LOWER(COALESCE(source_code, "")) LIKE ?', ['%call%']),
                self::MESSAGE => $q->whereRaw('LOWER(COALESCE(source_code, "")) LIKE ?', ['%message%'])
                    ->orWhereRaw('LOWER(COALESCE(source_code, "")) LIKE ?', ['%sms%'])
                    ->orWhereRaw('LOWER(COALESCE(connected_by, "")) LIKE ?', ['%message%']),
                self::FORM => $q->whereNull('whatsapp_id')
                    ->whereRaw('NOT (LOWER(COALESCE(source_code, "")) LIKE ?)', ['%whatsapp%'])
                    ->where('source_code', '<>', 'whatsapp')
                    ->where(function (Builder $inner) {
                        $inner->whereNull('connected_by')->orWhereRaw('LOWER(connected_by) NOT LIKE ?', ['%call%']);
                    })
                    ->whereRaw('LOWER(COALESCE(source_code, "")) NOT LIKE ?', ['%call%'])
                    ->whereRaw('LOWER(COALESCE(source_code, "")) NOT LIKE ?', ['%message%'])
                    ->whereRaw('LOWER(COALESCE(source_code, "")) NOT LIKE ?', ['%sms%']),
                default => $q->whereRaw('1 = 1'),
            };
        });
    }
}
