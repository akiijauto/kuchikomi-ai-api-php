<?php

declare(strict_types=1);

namespace App\Reply;

/**
 * 返信案1つ。
 *
 * Go版の Reply 構造体では見出しのフィールド名が Label だが、
 * このPHP版の契約(呼び出し側の型定義)では style という名前を使う。
 * 中身の役割(アプローチの違いを表す短い見出し)は Go版と同じもの。
 */
final class ReplyItem
{
    public function __construct(
        public readonly string $style,
        public readonly string $text,
    ) {
    }

    /** @return array{style: string, text: string} */
    public function toArray(): array
    {
        return ['style' => $this->style, 'text' => $this->text];
    }
}
